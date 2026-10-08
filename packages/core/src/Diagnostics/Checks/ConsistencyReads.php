<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\Panel;
use AzGuard\Panels\Reads;
use AzGuard\Sources\Database\DatabaseSource;

/**
 * `consistency.reads`: a panel that reads decisions from the default connection while that connection has read
 * hosts may decide on a replica that lags behind a change.
 *
 * @internal
 */
final readonly class ConsistencyReads implements DoctorCheck
{
    public function key(): string
    {
        return 'consistency.reads';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->panels() as $panel) {
            if ($panel->settings()->reads() !== Reads::Default) {
                continue;
            }

            foreach ($panel->attachedSources() as $source) {
                if ($source instanceof DatabaseSource && $source->hasReadHosts()) {
                    yield self::finding($panel);

                    break;
                }
            }
        }
    }

    public static function finding(Panel $panel): DoctorFinding
    {
        return DoctorFinding::warning('consistency.reads', 'Panel '.$panel->id().' reads decisions with consistency.reads = default from a connection with read hosts: '
            .'a decision can miss a change that has not reached the replica yet.', 'panel:'.$panel->id(), ['reads' => Reads::Default->value]);
    }
}
