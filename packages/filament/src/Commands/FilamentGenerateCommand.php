<?php

declare(strict_types=1);

namespace AzGuard\Filament\Commands;

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Filament\Attributes\ForFilament;
use AzGuard\Filament\Authorization\FilamentKey;
use AzGuard\Filament\Authorization\FilamentSurface;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\FilamentDefinitions;
use Filament\Facades\Filament;
use Filament\Panel as FilamentPanel;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Creates the permission enums of the resources, pages and widgets of a Filament panel in the folder of its AzGuard
 * panel, with the generators of the core: `azguard:make:permission` for the enums and, with `--with-policy`,
 * `azguard:make:policy`. The keys are the ones the Filament panel checks; the authority is only what `--authority`
 * says. Each enum is marked `#[ForFilament(Class)]`, so the doctor can tell a definition whose class is gone.
 */
final class FilamentGenerateCommand extends Command
{
    /** @var string */
    protected $signature = 'azguard:filament:generate
        {--filament-panel= : The Filament panel; the default one when omitted}
        {--panel= : The panel directory of the AzGuard panel, such as Admin; the guard panel of the plugin when omitted}
        {--authority=grants : Who decides the permissions: grants or policy}
        {--with-policy : With grants, also create the policy of each enum and bind it with #[PolicyFor]}
        {--only=* : Only resources, pages, widgets, or a class}
        {--force : Overwrite the files when they exist}
        {--dry-run : Print what would be created and write nothing}';

    /** @var string */
    protected $description = 'Create the permission enums of the resources, pages and widgets of a Filament panel';

    public function handle(): int
    {
        $authority = (string) $this->option('authority');

        if (! in_array($authority, ['grants', 'policy'], true)) {
            $this->components->error('--authority is grants or policy.');

            return self::INVALID;
        }

        try {
            $filament = $this->filamentPanel();
            $plugin = AzGuardPlugin::get($filament->getId());

            if ($plugin->getDefinitions() === FilamentDefinitions::Resources) {
                $this->components->error('The Filament panel "'.$filament->getId().'" takes its definitions from FilamentSource (FilamentDefinitions::Resources); enums for the same permissions would collide with them.');

                return self::INVALID;
            }
            $groups = $this->groups($plugin->keys($filament)->all());
        } catch (InvalidConfigurationException $error) {
            $this->components->error($error->getMessage());

            return self::INVALID;
        }
        $panel = (string) ($this->option('panel') ?: Str::studly($plugin->getGuardPanel()));
        $groups = array_values(array_filter($groups, $this->selected(...)));

        if ($groups === []) {
            $this->components->info('Nothing to generate.');

            return self::SUCCESS;
        }
        $failed = false;

        foreach ($groups as $group) {
            if ($this->option('dry-run') === true) {
                $this->line($panel.'/'.$group['group'].': '.count($group['cases']).' permission(s), '.implode(', ', array_map(static fn (string $key): string => $key, $group['cases'])));

                continue;
            }
            $failed = ! $this->generate($panel, $group, $authority) || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function filamentPanel(): FilamentPanel
    {
        $id = $this->option('filament-panel');

        if (is_string($id) && $id !== '') {
            return Filament::getPanels()[$id] ?? throw new InvalidConfigurationException('The Filament panel "'.$id.'" is not registered.');
        }

        return Filament::getDefaultPanel();
    }

    /**
     * The permissions by the group they are generated into: one enum for each resource, one for all pages, one for all
     * widgets.
     *
     * @param  list<FilamentKey>  $keys
     * @return list<array{group: string, classes: list<class-string>, surface: FilamentSurface, cases: array<string, string>, model: ?string}>
     */
    private function groups(array $keys): array
    {
        $groups = [];

        foreach ($keys as $key) {
            $segments = explode('.', $key->local);

            if ($key->surface === FilamentSurface::Resource) {
                $ability = array_pop($segments);
                $nav = $key->group === null ? '' : Str::studly($key->group);
                $path = implode('/', [...(preg_match('/\A[A-Z][A-Za-z0-9]*\z/', $nav) === 1 ? [$nav] : []), ...array_map(Str::studly(...), $segments)]);
                $case = Str::studly($ability);
            } else {
                $path = $key->surface === FilamentSurface::Page ? 'Pages' : 'Widgets';
                $case = Str::studly(implode('-', array_slice($segments, 1)));
            }

            if (isset($groups[$path]['cases'][$case])) {
                throw new InvalidConfigurationException('Two permissions of the group '.$path.' would both be the case '.$case.': '.$groups[$path]['cases'][$case].' and '.$key->local.'. Set $azguardKey on one of the classes.');
            }
            $groups[$path]['group'] = $path;
            $groups[$path]['surface'] = $key->surface;
            $groups[$path]['model'] ??= $key->model;
            $groups[$path]['classes'][$key->class] = $key->class;
            $groups[$path]['cases'][$case] = $key->local;
        }

        return array_values(array_map(static fn (array $group): array => [...$group, 'classes' => array_values($group['classes'])], $groups));
    }

    /** @param array{group: string, classes: list<class-string>, surface: FilamentSurface, cases: array<string, string>, model: ?string} $group */
    private function selected(array $group): bool
    {
        $only = array_values(array_filter((array) $this->option('only'), is_string(...)));

        if ($only === []) {
            return true;
        }
        $kind = match ($group['surface']) {
            FilamentSurface::Resource => 'resources',
            FilamentSurface::Page => 'pages',
            FilamentSurface::Widget => 'widgets',
        };

        foreach ($only as $want) {
            if ($want === $kind || in_array(ltrim($want, '\\'), $group['classes'], true)
                || array_filter($group['classes'], static fn (string $class): bool => class_basename($class) === $want) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs a command of the core into a buffer of its own: the output of the command that called it stays its own.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function runCore(string $command, array $arguments, BufferedOutput $output): int
    {
        return $this->getApplication()?->find($command)->run(new ArrayInput($arguments), $output) ?? self::FAILURE;
    }

    /**
     * @param  array{group: string, classes: list<class-string>, surface: FilamentSurface, cases: array<string, string>, model: ?string}  $group
     */
    private function generate(string $panel, array $group, string $authority): bool
    {
        $arguments = ['panel' => $panel, 'group' => $group['group'], '--authority' => $authority,
            '--case' => array_map(static fn (string $case, string $key): string => $case.'='.$key, array_keys($group['cases']), $group['cases'])];

        if ($group['model'] !== null) {
            $arguments['--model'] = $group['model'];
        }

        if ($this->option('force') === true) {
            $arguments['--force'] = true;
        }
        $output = new BufferedOutput;
        $code = $this->runCore('azguard:make:permission', $arguments, $output);
        $text = $output->fetch();
        $this->output->write($text);

        if ($code !== self::SUCCESS) {
            return false;
        }

        if (preg_match('/(?:Created|Overwritten)\s+(\S+Permission\.php)/', $text, $created) !== 1) {
            $this->components->error('The generator did not say where it wrote the enum of '.$group['group'].'.');

            return false;
        }
        $path = base_path($created[1]);
        $source = (string) file_get_contents($path);
        file_put_contents($path, EnumMarks::add($source, array_map(static fn (string $class): array => ['attribute' => ForFilament::class, 'class' => $class], $group['classes'])));

        if ($this->option('with-policy') === true && $authority === 'grants') {
            $enum = EnumMarks::classOf($source);

            if ($enum === null) {
                $this->components->error('The enum written to '.$created[1].' could not be read.');

                return false;
            }

            if (! enum_exists($enum, false)) {
                require_once $path;
            }
            $policy = ['panel' => $panel, 'group' => $group['group'], '--enum' => $enum];

            if ($this->option('force') === true) {
                $policy['--force'] = true;
            }
            $output = new BufferedOutput;
            $code = $this->runCore('azguard:make:policy', $policy, $output);
            $this->output->write($output->fetch());

            return $code === self::SUCCESS;
        }

        return true;
    }
}
