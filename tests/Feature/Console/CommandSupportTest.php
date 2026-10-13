<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Laravel\Console\CommandSupport;
use AzGuard\Laravel\Console\InvalidCommandInput;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

/*
 * A command of a module or an application uses CommandSupport and gets the exit codes of every AzGuard command:
 * 0 success, 2 input/panel/tenant/identity errors, 1 every other error.
 */

beforeEach(fn () => CrmWorld::seed());
afterEach(fn () => CrmWorld::resetRuntime());

function supportCommand(Closure $body): void
{
    $command = new class($body) extends Command
    {
        use CommandSupport;

        /** @var string */
        protected $signature = 'acme:support {--panel=} {--until=}';

        public function __construct(private readonly Closure $body)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            return $this->attempt(fn (): int => ($this->body)($this));
        }

        public function panelId(): string
        {
            return $this->selectPanel()->id();
        }

        public function until(): ?DateTimeImmutable
        {
            return $this->dateOption('until');
        }
    };
    app(Kernel::class)->registerCommand($command);
}

it('maps the outcome of the body to one exit code', function (Closure $body, int $exit, string $message): void {
    supportCommand($body);

    expect(Artisan::call('acme:support'))->toBe($exit)->and(Artisan::output())->toContain($message);
})->with([
    'success' => [static fn (): int => Command::SUCCESS, 0, ''],
    'invalid input' => [static fn (): never => throw new InvalidCommandInput('--until is malformed.'), 2, '--until is malformed.'],
    'identity error' => [static fn (): never => throw new InvalidIdentityException('Invalid subject reference.'), 2, 'Invalid subject reference.'],
    'other AzGuard error' => [static fn (): never => throw new StaleSelectionException('The grant changed.'), 1, 'StaleSelectionException: The grant changed.'],
    'unexpected error' => [static fn (): never => throw new RuntimeException('connection secret'), 1, 'The command failed with RuntimeException. Run it with -v'],
]);

it('keeps the message of an unexpected error out of the output unless it runs verbosely', function (): void {
    supportCommand(static fn (): never => throw new RuntimeException('connection secret'));

    expect(Artisan::call('acme:support'))->toBe(1)->and(Artisan::output())->not->toContain('connection secret')
        ->and(Artisan::call('acme:support', ['-v' => true]))->toBe(1)->and(Artisan::output())->toContain('connection secret');
});

it('selects the panel of --panel through the panel resolver and refuses an unknown one with exit 2', function (): void {
    W::panel();
    $seen = [];
    supportCommand(static function (Command $command) use (&$seen): int {
        $seen[] = $command->panelId();

        return Command::SUCCESS;
    });

    expect(Artisan::call('acme:support', ['--panel' => 'crm']))->toBe(0)->and($seen)->toBe(['crm'])
        ->and(Artisan::call('acme:support', ['--panel' => 'ghost']))->toBe(2);
});

it('reads an ISO-8601 option as UTC and refuses a malformed one with exit 2', function (): void {
    $seen = [];
    supportCommand(static function (Command $command) use (&$seen): int {
        $seen[] = $command->until()?->format(DATE_ATOM);

        return Command::SUCCESS;
    });

    expect(Artisan::call('acme:support', ['--until' => '2026-12-31T18:00:00+02:00']))->toBe(0)->and($seen)->toBe(['2026-12-31T16:00:00+00:00'])
        ->and(Artisan::call('acme:support', ['--until' => 'tomorrow']))->toBe(2);
});
