<?php

declare(strict_types=1);

namespace AzGuard\Storage;

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Kernel\Identity\IdentityCodec;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Throwable;

final class Storage
{
    use DetectsConcurrencyErrors;

    /** @var array<string, PanelState> */
    private array $locked = [];

    private ?StorageMutation $current = null;

    private bool $schemaChecked = false;

    public function __construct(private readonly string $id, private readonly Connection $connection,
        private readonly string $prefix = 'azg_', private readonly string $hostKeys = 'string')
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $id) !== 1
            || preg_match('/\A([a-z][a-z0-9_]{0,19})?\z/', $prefix) !== 1) {
            throw InvalidConfigurationException::failing('storage', 'Invalid storage identity or prefix.');
        }

        if (! in_array($hostKeys, ['string', 'bigint', 'uuid', 'ulid'], true)) {
            throw InvalidConfigurationException::failing('host_keys', 'Invalid host key type.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function connectionName(): string
    {
        return $this->connection->getName() ?? throw InvalidConfigurationException::failing('storage', 'Storage requires a named connection.');
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function hostKeys(): string
    {
        return $this->hostKeys;
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function table(string $base): Builder
    {
        return $this->connection->table($this->prefix.$base)->useWritePdo();
    }

    public function state(string $panel): ?PanelState
    {
        PermissionGrammar::assertPanelId($panel);
        $this->assertSchema();
        $row = $this->table('panel_state')->where('panel', $panel)->first();

        return $row === null ? null : $this->panelState($row);
    }

    /**
     * @template T
     *
     * @param  string|list<string>  $panels
     * @param  Closure(StorageMutation): T  $work
     * @return T
     */
    public function mutate(string|array $panels, Closure $work, int $attempts = 3): mixed
    {
        $panels = is_string($panels) ? [$panels] : $panels;
        foreach ($panels as $panel) {
            PermissionGrammar::assertPanelId($panel);
        }
        $panels = array_values(array_unique($panels));
        sort($panels, SORT_STRING);

        if ($attempts < 1 || $panels === []) {
            throw new InvalidArgumentException('Mutation needs panels and at least one attempt.');
        }
        $this->assertSchema();
        $nested = $this->connection->transactionLevel() > 0;
        for ($attempt = 1; ; $attempt++) {
            $committed = false;

            try {
                return $this->connection->transaction(function () use ($panels, $work, $nested, &$committed): mixed {
                    $this->connection->afterCommit(static function () use (&$committed): void {
                        $committed = true;
                    });

                    return $this->perform($panels, $work, $nested);
                }, 1);
            } catch (Throwable $error) {
                if ($committed || $nested || $attempt >= $attempts || ! $this->causedByConcurrencyError($error)) {
                    throw $error;
                }
                usleep(random_int(5, 25) * $attempt * 1000);
            }
        }
    }

    /** @template T
     * @param  list<string>  $panels
     * @param  Closure(StorageMutation): T  $work
     * @return T
     */
    private function perform(array $panels, Closure $work, bool $nested): mixed
    {
        $parent = $this->current;
        $previousLocks = $this->locked;
        $previousTouches = $this->pendingTouches;
        $mutation = null;

        try {
            $states = [];
            foreach ($panels as $panel) {
                $states[$panel] = $this->locked[$panel] ??= $this->lockPanel($panel);
            }
            $mutation = new StorageMutation($this, $states, $nested);
            $this->current = $mutation;
            $result = $work($mutation);
            foreach ($mutation->touched() as $panel) {
                $this->pendingTouches[$panel] = true;
            }

            if ($parent === null) {
                foreach (array_keys($this->pendingTouches) as $panel) {
                    $this->table('panel_state')->where('panel', $panel)->increment('version', 1, ['updated_at' => gmdate('Y-m-d H:i:s')]);
                }
            }
            foreach ($mutation->callbacks() as $callback) {
                $this->connection->afterCommit($callback);
            }

            return $result;
        } catch (Throwable $error) {
            $this->locked = $previousLocks;
            $this->pendingTouches = $previousTouches;

            throw $error;
        } finally {
            $mutation?->close();
            $this->current = $parent;

            if ($parent === null) {
                $this->locked = [];
                $this->pendingTouches = [];
            }
        }
    }

    /** @var array<string, true> */
    private array $pendingTouches = [];

    /** @return array{version: int, identity_codec: int, storage_id: string, prefix: string, host_keys: string} */
    public function schema(): array
    {
        return ['version' => 1, 'identity_codec' => IdentityCodec::VERSION,
            'storage_id' => $this->id, 'prefix' => $this->prefix, 'host_keys' => $this->hostKeys];
    }

    private function assertSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $expected = $this->schema();
        $found = null;

        try {
            $row = $this->table('storage_state')->where('id', 1)->first();

            if ($row !== null) {
                $found = json_decode($row->schema, true, flags: JSON_THROW_ON_ERROR);
            }
        } catch (QueryException|JsonException $error) {
            throw new StorageMismatchException('Storage '.$this->id.' expected '.json_encode($expected).'; cannot read storage_state: '.$error->getMessage(), 0, $error);
        }

        if (! is_array($found) || count($found) !== count($expected)
            || array_filter($expected, static fn (int|string $value, string $key): bool => ($found[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH) !== []) {
            throw new StorageMismatchException('Storage '.$this->id.' expected '.json_encode($expected).'; found '.json_encode($found).'.');
        }
        $this->schemaChecked = true;
    }

    private function lockPanel(string $panel): PanelState
    {
        $query = $this->table('panel_state')->where('panel', $panel);
        $row = $this->connection->getDriverName() === 'sqlite' ? null : $query->lockForUpdate()->first();

        if ($row === null) {
            $this->table('panel_state')->insertOrIgnore(['panel' => $panel, 'version' => 0,
                'incarnation' => strtolower((string) Str::ulid()), 'updated_at' => gmdate('Y-m-d H:i:s')]);
            $row = $query->lockForUpdate()->firstOrFail();
        }

        return $this->panelState($row);
    }

    private function panelState(stdClass $row): PanelState
    {
        return new PanelState($row->panel, (int) $row->version, $row->incarnation,
            new DateTimeImmutable($row->updated_at, new DateTimeZone('UTC')));
    }
}
