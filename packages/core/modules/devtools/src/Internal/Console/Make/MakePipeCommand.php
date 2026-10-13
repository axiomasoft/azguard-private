<?php

declare(strict_types=1);

namespace AzGuard\DevTools\Internal\Console\Make;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\Changes\ChangePipe;
use AzGuard\DevTools\Internal\Scaffold\GeneratedFile;
use AzGuard\DevTools\Internal\Scaffold\Layout;
use AzGuard\DevTools\Internal\Scaffold\StubStore;
use Closure;

/**
 * A pipe every change of grants passes through, in `{Panel}/Changes/` or `Shared/Changes/`.
 */
final class MakePipeCommand extends MakeCommand
{
    /** @var string */
    protected $signature = 'azguard:make:pipe
        {name : The pipe, such as RequireReason}
        {--panel= : Put it in this panel}
        {--shared : Put it where panels share things}
        {--force : Overwrite the file when it exists}';

    /** @var string */
    protected $description = 'Create a change pipe of an AzGuard panel';

    protected function stubName(): string
    {
        return 'change-pipe';
    }

    protected function plan(Layout $layout, StubStore $stubs): array
    {
        $place = $this->panelOrShared($layout, 'Changes', $this->stringOption('panel'), (bool) $this->option('shared'));
        $class = $layout->className($this->stringArgument('name'));

        return [new GeneratedFile($place->file($class), $stubs->render('change-pipe', [
            'namespace' => $place->namespace,
            'imports' => StubStore::imports([Change::class, ChangePipe::class, ChangeResult::class, Closure::class]),
            'class' => $class,
        ]))];
    }
}
