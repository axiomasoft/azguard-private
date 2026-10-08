<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands\Make;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;
use AzGuard\Laravel\Console\Scaffold\GeneratedFile;
use AzGuard\Laravel\Console\Scaffold\Layout;
use AzGuard\Laravel\Console\Scaffold\StubStore;
use Illuminate\Support\Str;

/**
 * A restriction in `{Panel}/Restrictions/` or `Shared/Restrictions/`; it only forbids.
 */
final class MakeRestrictionCommand extends MakeCommand
{
    /** @var string */
    protected $signature = 'azguard:make:restriction
        {name : The restriction, such as AccountLocked}
        {--panel= : Put it in this panel}
        {--shared : Put it where panels share things}
        {--force : Overwrite the file when it exists}';

    /** @var string */
    protected $description = 'Create a restriction of an AzGuard panel';

    protected function stubName(): string
    {
        return 'restriction';
    }

    protected function plan(Layout $layout, StubStore $stubs): array
    {
        $place = $this->panelOrShared($layout, 'Restrictions', $this->stringOption('panel'), (bool) $this->option('shared'));
        $class = $layout->className($this->stringArgument('name'), 'Restriction');

        return [new GeneratedFile($place->file($class), $stubs->render('restriction', [
            'namespace' => $place->namespace,
            'imports' => StubStore::imports([AccessRequest::class, EvaluationContext::class, Restriction::class, RestrictionResult::class]),
            'class' => $class,
            'key' => Str::kebab(substr($class, 0, -11)),
        ]))];
    }
}
