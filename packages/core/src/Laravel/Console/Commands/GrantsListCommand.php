<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Changes\GrantFilter;
use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Sources\Database\DatabaseSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Lists every stored grant of one subject in one panel and tenant, in every origin and context, expired ones
 * included: roles and permissions, their contexts, expiry and fields. Reads only.
 */
final class GrantsListCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:grants:list
        {subject : type:id, or the id when there is one subject model}
        {--panel= : The panel; otherwise the panel resolver decides}
        {--tenant= : type:id, required for a panel with tenants}
        {--json : Print JSON}';

    /** @var string */
    protected $description = 'List the stored AzGuard grants of a subject';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $subject = $this->subjectRef($this->stringArgument('subject'));
            $panel = $this->selectPanel($subject);
            $access = $this->access($panel);
            $tenant = $this->tenantOf($panel);
            $writer = $panel->writer();
            $now = CarbonImmutable::now('UTC')->toDateTimeImmutable();
            $grants = [];

            if ($writer instanceof DatabaseSource) {
                foreach ($writer->grantOrigins($panel, $tenant, $subject) as $origin) {
                    $manager = $access->fromOrigin($origin)->grants();
                    $filter = new GrantFilter(subject: $subject, limit: GrantFilter::MAX_LIMIT);
                    do {
                        $page = $manager->page($filter);
                        foreach ($page->items as $grant) {
                            $grants[] = $this->grantArray($grant, $now);
                        }
                        $filter = $filter->after($page->nextCursor);
                    } while ($page->nextCursor !== null);
                }
            }

            if ($this->option('json') === true) {
                $this->printJson(['panel' => $panel->id(), 'tenant' => $tenant->key(), 'subject' => $subject->key(), 'grants' => $grants]);

                return self::SUCCESS;
            }

            if ($grants === []) {
                $this->components->info('Subject '.$subject->key().' has no stored grants in panel '.$panel->id().' and tenant '.$tenant->key().'.');

                return self::SUCCESS;
            }
            $this->table(['Id', 'Kind', 'Key', 'Context', 'Origin', 'Until', 'Fields'], array_map(static fn (array $grant): array => [
                $grant['id'], $grant['kind'], $grant['key'], $grant['context'], $grant['origin'],
                ($grant['until'] ?? 'never').($grant['expired'] ? ' (expired)' : ''),
                $grant['fields'] === [] ? '' : json_encode($grant['fields'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], $grants));

            return self::SUCCESS;
        });
    }
}
