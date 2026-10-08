<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Concerns;

use AzGuard\AzGuardManager;
use AzGuard\Changes\ActingActor;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\GrantRecord;
use AzGuard\Contracts\PanelAccess;
use AzGuard\Exceptions\AmbiguousPanelException;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * @internal Input and output rules shared by the AzGuard commands.
 *
 * - The panel is picked only by the panel resolver, from `--panel`, full names and the subject; an unresolved panel is
 *   an error, never a guess.
 * - `{subject}` is `type:id`, or a bare id when the panels in question have one subject model.
 * - A panel with tenants needs `--tenant=type:id` before anything is read or written; no command works across tenants.
 * - Changes run as the system actor whose reason is the command name.
 * - `--force` is required for irreversible changes in production.
 * - Exit codes: 0 success, 1 a refused or failed operation, 2 invalid input (panel, tenant, subject, option, a missing
 *   `--force`).
 *
 * @phpstan-require-extends Command
 */
trait InteractsWithAzGuard
{
    /**
     * Runs the body of a command and turns its failure into an error line and an exit code.
     *
     * @param  Closure(): int  $body
     */
    protected function attempt(Closure $body): int
    {
        try {
            return $body();
        } catch (InvalidCommandInput|PanelNotResolvedException|UnknownPanelException|AmbiguousPanelException|ConflictingPanelException
            |InvalidPanelIdException|TenantRequiredException|TenantMismatchException|InvalidIdentityException|SubjectNotAcceptedException $error) {
                $this->components->error($error->getMessage());

                return Command::INVALID;
            } catch (AzGuardException $error) {
                $this->components->error(class_basename($error).': '.$error->getMessage());

                return Command::FAILURE;
            } catch (Throwable $error) {
                // A database or framework message may carry connection details; it is shown on request only.
                $this->components->error('The command failed with '.$error::class
                    .($this->output->isVerbose() ? ': '.$error->getMessage() : '. Run it with -v to see the message.'));

                return Command::FAILURE;
            }
    }

    /**
     * The panel of the command by the rule of the panel resolver: `--panel`, full names of permissions and roles, the
     * subject.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $roles
     */
    protected function selectPanel(?SubjectRef $subject = null, array $permissions = [], array $roles = []): Panel
    {
        $panel = $this->stringOption('panel');

        return app(PanelResolver::class)->select($subject, $permissions, $roles, $panel === null ? [] : [$panel]);
    }

    /**
     * The subject of `type:id`, or of a bare id when the panel of `--panel` (or every panel without it) has one
     * subject model.
     */
    protected function subjectRef(string $input): SubjectRef
    {
        if (str_contains($input, ':')) {
            [$type, $id] = $this->identity($input, 'subject');

            return SubjectRef::of($type, $id);
        }
        $panel = $this->stringOption('panel');
        $registry = app(PanelRegistry::class);
        $models = [];
        foreach ($panel === null ? $registry->all() : [$registry->get($panel)] as $each) {
            foreach ($each->subjectModels() as $model) {
                $models[$model] = true;
            }
        }

        if (count($models) !== 1 || $input === '') {
            throw new InvalidCommandInput('Subject "'.$input.'" has no type and '.count($models).' subject models are possible: pass the subject as type:id.');
        }
        /** @var class-string<Model> $model */
        $model = array_key_first($models);

        return SubjectRef::of((new $model)->getMorphClass(), $input);
    }

    /**
     * The tenant of `--tenant`; the global tenant for a panel without tenants. A panel with tenants and no `--tenant`
     * is refused before anything is read or written.
     *
     * @throws TenantRequiredException
     */
    protected function tenantOf(Panel $panel): TenantRef
    {
        $input = $this->stringOption('tenant');

        if ($input !== null) {
            [$type, $id] = $this->identity($input, '--tenant');

            if ($type !== $panel->tenants()->definition()?->type()) {
                throw new TenantMismatchException('Panel '.$panel->id().' does not take tenants of the type "'.$type.'".');
            }

            return TenantRef::of($type, $id);
        }

        if ($panel->tenants()->mode() !== 'none') {
            throw new TenantRequiredException('Panel '.$panel->id().' has tenants: pass --tenant=type:id. No command works across tenants.');
        }

        return TenantRef::global();
    }

    /** The access to the panel in the tenant of `--tenant`, in the origin given for changes. */
    protected function access(Panel $panel, string $origin = IdentityCodec::DEFAULT_ORIGIN): PanelAccess
    {
        return app(AzGuardManager::class)->panel($panel->id())->inTenant($this->tenantOf($panel))->fromOrigin($origin);
    }

    protected function originOption(): string
    {
        return $this->stringOption('origin') ?? IdentityCodec::DEFAULT_ORIGIN;
    }

    /** The assignment scope of `--on=type:id`, or null. */
    protected function contextOption(): ?AssignmentScopeRef
    {
        $input = $this->stringOption('on');

        if ($input === null) {
            return null;
        }
        [$type, $id] = $this->identity($input, '--on');

        return AssignmentScopeRef::of($type, $id);
    }

    /** An ISO-8601 date or date-time of an option; a time without an offset is UTC. */
    protected function dateOption(string $name): ?DateTimeImmutable
    {
        $input = $this->stringOption($name);

        if ($input === null) {
            return null;
        }

        if (preg_match('/\A\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?\z/', $input) !== 1) {
            throw new InvalidCommandInput('--'.$name.' must be an ISO-8601 date or date-time such as 2026-12-31T18:00:00Z.');
        }

        try {
            $date = new DateTimeImmutable($input, new DateTimeZone('UTC'));
        } catch (Exception) {
            $date = null;
        }

        // A day or hour out of range is rolled over by PHP with a warning; such a date is refused, not shifted.
        if ($date === null || DateTimeImmutable::getLastErrors() !== false) {
            throw new InvalidCommandInput('--'.$name.' must be an ISO-8601 date or date-time; '.$input.' does not exist.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Grant fields of the repeatable `--field=key=value`; a key given twice is a list. Values stay strings: the field
     * schema of the panel validates and casts them.
     *
     * @param  array<mixed>  $inputs
     * @return array<string, string|list<string>>
     */
    protected function fields(array $inputs): array
    {
        $fields = [];
        foreach ($inputs as $input) {
            if (! is_string($input) || ! str_contains($input, '=') || str_starts_with($input, '=')) {
                throw new InvalidCommandInput('--field must have the form key=value.');
            }
            [$key, $value] = explode('=', $input, 2);

            if (! isset($fields[$key])) {
                $fields[$key] = $value;
            } else {
                $fields[$key] = [...(array) $fields[$key], $value];
            }
        }

        return $fields;
    }

    /**
     * Runs a change as the system actor whose reason is the name of the command.
     *
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     */
    protected function asSystem(Closure $change): mixed
    {
        return app(ActingActor::class)->run(ActorRef::system((string) $this->getName()), $change);
    }

    /** Refuses an irreversible change in production unless `--force` is given. */
    protected function confirmIrreversible(string $what, bool $force): void
    {
        if ($this->laravel->environment('production') && ! $force) {
            throw new InvalidCommandInput($what.' cannot be undone: pass --force to run it in production.');
        }
    }

    /**
     * A stored grant as the commands print it; values of fields with a secret-like name are redacted.
     *
     * @return array{id: string, kind: string, key: string, tenant: string, context: string, origin: string, until: ?string, expired: bool, fields: array<mixed>}
     */
    protected function grantArray(GrantRecord $grant, DateTimeImmutable $now): array
    {
        return [
            'id' => $grant->id,
            'kind' => $grant->isRole() ? 'role' : 'permission',
            'key' => $grant->role?->key() ?? $grant->permission?->local() ?? '',
            'tenant' => $grant->scope->tenant->key(),
            'context' => $grant->scope->context->key(),
            'origin' => $grant->origin,
            'until' => $grant->until?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'expired' => $grant->until !== null && $grant->until <= $now,
            'fields' => $this->redacted($grant->fields),
        ];
    }

    /**
     * Values under a secret-like name (pass, secret, token, credential, key, private, dsn, auth), at any depth, as
     * `[redacted]`; in any other string the password of a URL with credentials (`scheme://user:password@host`).
     *
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    protected function redacted(array $values): array
    {
        $redacted = [];
        foreach ($values as $name => $value) {
            $redacted[$name] = match (true) {
                is_string($name) && preg_match('/pass|secret|token|credential|key|private|dsn|auth/i', $name) === 1 => '[redacted]',
                is_array($value) => $this->redacted($value),
                is_string($value) => (string) preg_replace('#(://[^:/@\s]*:)[^@\s]*@#', '$1[redacted]@', $value),
                default => $value,
            };
        }

        return $redacted;
    }

    /** One line with the outcome of a change: its status, the number of effects and the resulting state version. */
    protected function reportChange(string $what, ChangeResult $result): int
    {
        $this->components->info($what.': '.$result->status->value.', '.count($result->effects).' effect(s), state version '.$result->state->version.'.');

        return Command::SUCCESS;
    }

    /** @param array<mixed> $data */
    protected function printJson(array $data): void
    {
        $this->line(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    protected function stringOption(string $name): ?string
    {
        if (! $this->hasOption($name)) {
            return null;
        }
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidCommandInput('--'.$name.' needs a value.');
        }

        return $value;
    }

    protected function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidCommandInput('The argument '.$name.' needs a value.');
        }

        return $value;
    }

    /** @return array{string, string} */
    private function identity(string $input, string $what): array
    {
        $parts = explode(':', $input, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidCommandInput($what.' must have the form type:id.');
        }

        return [$parts[0], $parts[1]];
    }
}
