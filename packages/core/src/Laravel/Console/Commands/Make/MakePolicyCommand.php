<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands\Make;

use AzGuard\Laravel\Console\Concerns\InvalidCommandInput;
use AzGuard\Laravel\Console\Scaffold\EnumSource;
use AzGuard\Laravel\Console\Scaffold\GeneratedFile;
use AzGuard\Laravel\Console\Scaffold\Layout;
use AzGuard\Laravel\Console\Scaffold\PolicyFile;
use AzGuard\Laravel\Console\Scaffold\StubStore;
use ReflectionEnum;

/**
 * The policy of a group, `Policies/{Group}/{Name}Policy.php`, for the one permission enum of the group, or for the enum
 * of `--enum` with `#[PolicyFor]`. More than one candidate is an error that names the way out: discovery would refuse
 * the pair the same way.
 */
final class MakePolicyCommand extends MakeCommand
{
    /** @var string */
    protected $signature = 'azguard:make:policy
        {panel : The panel directory, such as Admin}
        {group : The group of the policy, such as Orders or Sales/Orders}
        {--enum= : The permission enum to decide, as a class name; the policy is bound to it with #[PolicyFor]}
        {--force : Overwrite the file when it exists}';

    /** @var string */
    protected $description = 'Create the policy of a group of a panel with #[Decides] for each permission';

    protected function stubName(): string
    {
        return 'policy';
    }

    protected function plan(Layout $layout, StubStore $stubs): array
    {
        $panel = $layout->existingPanel($layout->panelName($this->stringArgument('panel')));
        $group = $layout->group($this->stringArgument('group'));
        $relative = implode('/', $group);
        $name = Layout::nameOf($group[array_key_last($group)]);
        $place = $panel->in($layout->folder('policies').'/'.$relative);
        $class = $name.'Policy';
        $enum = $this->stringOption('enum');

        if ($enum !== null) {
            [$enumClass, $cases] = $this->enumOf($enum);

            return [new GeneratedFile($place->file($class), PolicyFile::render($stubs, $place, $class, $enumClass, $cases, true))];
        }
        [$enumClass, $cases] = $this->enumOfGroup($layout, $panel->in($layout->folder('permissions').'/'.$relative)->directory, $relative);
        $others = array_values(array_filter(
            glob($place->directory.'/*.php') ?: [],
            static fn (string $file): bool => basename($file) !== $class.'.php' && ! str_contains((string) file_get_contents($file), '#[PolicyFor('),
        ));

        if ($others !== []) {
            throw new InvalidCommandInput('The group '.$relative.' already has the policy '.basename($others[0], '.php').', so a second one without #[PolicyFor] would be an ambiguous pair: pass --enum='
                .$enumClass.' to bind this policy to its enum with #[PolicyFor].');
        }

        return [new GeneratedFile($place->file($class), PolicyFile::render($stubs, $place, $class, $enumClass, $cases, false))];
    }

    /**
     * @return array{class-string, list<string>}
     */
    private function enumOf(string $name): array
    {
        $name = ltrim($name, '\\');

        if (! enum_exists($name) || (new ReflectionEnum($name))->getBackingType()?->getName() !== 'string') {
            throw new InvalidCommandInput('--enum must be a string-backed enum that can be loaded; "'.$name.'" is not.');
        }
        $cases = array_values(array_map(static fn ($case): string => $case->getName(), (new ReflectionEnum($name))->getCases()));

        if ($cases === []) {
            throw new InvalidCommandInput($name.' has no cases to decide.');
        }

        return [$name, $cases];
    }

    /**
     * @return array{class-string, list<string>}
     */
    private function enumOfGroup(Layout $layout, string $directory, string $relative): array
    {
        $found = [];
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $enum = EnumSource::read((string) file_get_contents($file));

            if ($enum !== null) {
                $found[] = $enum;
            }
        }

        if ($found === []) {
            throw new InvalidCommandInput('The group '.$relative.' has no permission enum in '.$layout->relative($directory).': run azguard:make:permission first, or pass --enum.');
        }

        if (count($found) > 1) {
            throw new InvalidCommandInput('The group '.$relative.' has '.count($found).' permission enums ('.implode(', ', array_map(static fn (EnumSource $enum): string => $enum->class, $found))
                .'): pass --enum=<class> to pick one, and the policy carries #[PolicyFor].');
        }

        if ($found[0]->cases === []) {
            throw new InvalidCommandInput($found[0]->class.' has no cases to decide.');
        }

        return [$found[0]->class, $found[0]->cases];
    }
}
