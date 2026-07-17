<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\ResolvesGuardNamespaces;
use AzGuard\Commands\Concerns\SupportsForcefulGeneration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class MakeGuardPermissionCommand extends Command
{
    use ResolvesGuardNamespaces;
    use SupportsForcefulGeneration;

    protected $signature = 'make:guard-permission
        {panel : Panel name (e.g. App)}
        {domain : Domain name (e.g. Documents)}
        {name? : Enum case name (e.g. View)}
        {--path=app/Guards}
        {--force : Overwrite existing files}';

    protected $description = 'Add a case to an existing Permissions enum or create one';

    public function handle(): int
    {
        $panel = (string) $this->argument(key: 'panel');
        $domain = (string) $this->argument(key: 'domain');
        $caseName = $this->argument(key: 'name');
        $pathOption = (string) $this->option(key: 'path');

        $enumPath = $this->domainPath(
            basePath: $this->guardBasePath(path: $pathOption, panel: $panel),
            domain: $domain,
        ).'/Permissions/'.$domain.'Permission.php';

        if (! File::exists(path: $enumPath)) {
            $this->call(command: 'make:guard-panel', arguments: [
                'panel' => $panel,
                'domain' => $domain,
                '--path' => $pathOption,
                '--force' => $this->shouldForce(),
            ]);

            return self::SUCCESS;
        }

        if (! is_string($caseName) || $caseName === '') {
            $this->error('Specify a case name: make:guard-permission App Documents Export');

            return self::FAILURE;
        }

        $domainKey = $this->domainKey(domain: $domain);
        $caseKey = Str::snake(value: $caseName);
        $enumCase = "    case {$caseName} = '{$domainKey}.{$caseKey}';\n";

        $content = File::get(path: $enumPath);
        $replaced = 0;
        $content = str_replace(
            search: "\n    case ",
            replace: "\n".$enumCase.'    case ',
            subject: $content,
            count: $replaced,
        );

        File::put(path: $enumPath, contents: $content);
        $this->info("Added case {$caseName} to {$enumPath}");

        return self::SUCCESS;
    }
}
