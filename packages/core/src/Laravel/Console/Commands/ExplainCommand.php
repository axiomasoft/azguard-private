<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\ModelSubjectResolver;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Laravel\Console\CommandSupport;
use AzGuard\Laravel\Console\InvalidCommandInput;
use Illuminate\Console\Command;

final class ExplainCommand extends Command
{
    use CommandSupport;

    /** @var string */
    protected $signature = 'azguard:explain
        {subject : type:id}
        {permission : The permission in any accepted form: posts.view, admin.posts.view or admin:posts.view}
        {--panel= : The panel; otherwise the panel resolver decides}
        {--tenant= : type:id of the tenant, required for a panel with tenants}
        {--context= : type:id of an assignment scope}
        {--json : Print JSON}';

    /** @var string */
    protected $description = 'Explain one AzGuard authorization decision with secrets redacted';

    public function handle(Authorizer $authorizer, ModelSubjectResolver $subjects): int
    {
        return $this->attempt(function () use ($authorizer, $subjects): int {
            [$type, $id] = $this->identity($this->argument('subject'));
            $subject = SubjectRef::of($type, $id);
            [$panel, $permission] = $authorizer->resolve($subject, (string) $this->argument('permission'), $this->option('panel'));

            if ($subjects->resolve($panel, $subject) === null) {
                throw new InvalidCommandInput('The subject does not exist in panel '.$panel->id().'.');
            }

            $request = AccessRequest::for($subject, $permission);

            if ($this->option('tenant') !== null) {
                [$type, $id] = $this->identity($this->option('tenant'));
                $request = $request->inTenant(TenantRef::of($type, $id));
            }

            if ($this->option('context') !== null) {
                [$type, $id] = $this->identity($this->option('context'));
                $request = $request->on(AssignmentScopeRef::of($type, $id));
            }

            $explanation = $authorizer->explain($panel, $request);

            if ($this->option('json')) {
                $this->line(json_encode($explanation->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            } else {
                $this->line($explanation->decision()->effect->value.': '.$explanation->decision()->reason->value);
                $this->table(['Stage', 'Component', 'Outcome', 'Detail'], array_map(static fn (array $step): array => [
                    $step['stage'], $step['component'] ?? '', $step['outcome'],
                    json_encode($step['detail'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ], $explanation->steps()));
            }

            return self::SUCCESS;
        });
    }

    /** @return array{string, string} */
    private function identity(mixed $input): array
    {
        if (! is_string($input) || ! str_contains($input, ':')) {
            throw new InvalidCommandInput('An identity has the form type:id.');
        }

        [$type, $id] = explode(':', $input, 2);

        return [$type, $id];
    }
}
