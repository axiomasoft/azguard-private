<?php

declare(strict_types=1);

namespace AzGuard\Filament\Editors;

use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\GrantFilter;
use AzGuard\Changes\GrantPage;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Filament\Actions\ExplainAction;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\Contracts\FilamentFormExtension;
use AzGuard\Filament\Forms\SchemaFields;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use Closure;
use DateTimeImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * The stored grants of one kind in one managed panel and, where the editor chooses it, one tenant: a table over the
 * pages of the grant manager, a form that grants, the edit of the expiry and the fields of a row, the revocation of a
 * row or of the selected rows in one change, and «Why?».
 *
 * The panel, the tenant and every other filter are values from the client and are checked again on every read and
 * write; the cursors of the pages and the fingerprint of the row being edited are set by the server only.
 *
 * @internal
 */
abstract class ListGrants extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * The cursor of each page after the first, for the filter in `$grantCursorFilter`.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $grantCursors = [];

    #[Locked]
    public ?string $grantCursorFilter = null;

    /**
     * The grant whose edit form is open, with the fingerprint the form was filled from.
     *
     * @var array{id: string, fingerprint: string}|null
     */
    #[Locked]
    public ?array $editing = null;

    /**
     * The last answer of «Why?», built by the server.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $explanation = null;

    /**
     * The editors of the targets checked in this request, by panel and tenant; never part of the snapshot.
     *
     * @var array<string, GrantEditor>
     */
    private array $editors = [];

    /** @return 'role'|'permission' */
    abstract protected static function kind(): string;

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $kind = static::kind();
        $label = $kind === 'role' ? 'Role' : 'Permission';

        return $table
            ->records(fn (int $page, int $recordsPerPage): Paginator => $this->grantPage($page, $recordsPerPage))
            ->columns([
                TextColumn::make('subject_label')->label('Subject')->description(static fn (array $record): string => $record['subject']),
                TextColumn::make('key_label')->label($label)->description(static fn (array $record): string => $record['key']),
                TextColumn::make('context_label')->label('Context'),
                TextColumn::make('origin')->label('Origin')->badge()->color('gray'),
                TextColumn::make('until')->label('Until')->dateTime()->placeholder('never'),
                TextColumn::make('granted_by')->label('Granted by')->placeholder('—'),
                TextColumn::make('fields')->label('Fields')->placeholder('—')
                    ->state(static fn (array $record): ?string => self::fieldsText($record['fields'])),
            ])
            ->filters([
                Filter::make('grants')->columns(3)->schema($this->filterSchema($label)),
            ])
            ->deferFilters(false)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->paginationMode(PaginationMode::Simple)
            ->selectCurrentPageOnly()
            ->headerActions([
                $this->createAction($label),
                ExplainAction::make()->visible(fn (): bool => static::getResource()::can('view') && $this->editor() !== null)
                    ->fillForm(fn (): array => ['subject' => null, 'permission' => null, 'context_type' => null])
                    ->schema(fn (): array => ExplainAction::form($this->editor()))
                    ->action(fn (array $data) => $this->explain($data)),
            ])
            ->recordActions([
                ExplainAction::make(ExplainAction::RECORD)->visible(fn (): bool => static::getResource()::can('view'))
                    ->fillForm(static fn (array $record): array => [
                        'subject' => $record['subject'],
                        'permission' => $kind === 'permission' ? $record['key'] : null,
                        'context_type' => $record['context_type'] ?? GrantEditor::TENANT_WIDE,
                        'context' => $record['context'],
                    ])
                    ->schema(fn (): array => ExplainAction::form($this->editor()))
                    ->action(fn (array $data) => $this->explain($data)),
                Action::make('edit')->label('Edit')
                    ->visible(static fn (): bool => static::getResource()::can('update'))
                    ->fillForm(fn (array $record): array => $this->editForm($record))
                    ->schema(fn (): array => [
                        DateTimePicker::make('until')->label('Until'),
                        Group::make(fn (): array => $this->fieldComponents())->columns(2),
                    ])
                    ->action(fn (array $record, array $data, Schema $schema) => $this->write('update', fn (GrantEditor $editor, Model $user): ChangeResult => $editor->update(
                        $record['id'],
                        $this->editingFingerprint($record['id']),
                        $this->submitted($data, $schema),
                        $user,
                    ), statePath: $schema->getStatePath())),
                Action::make('revoke')->label('Revoke')->color('danger')->requiresConfirmation()
                    ->visible(static fn (): bool => static::getResource()::can('delete'))
                    ->action(fn (array $record) => $this->write('delete', static fn (GrantEditor $editor, Model $user): ChangeResult => $editor->revoke([$record['id']], $user))),
            ])
            ->toolbarActions([
                BulkAction::make('revoke')->label('Revoke selected')->color('danger')->requiresConfirmation()
                    ->visible(static fn (): bool => static::getResource()::can('delete'))
                    ->fetchSelectedRecords(false)
                    ->action(fn () => $this->write('delete', fn (GrantEditor $editor, Model $user): ChangeResult => $editor->revoke($this->selectedGrantIds(), $user))),
            ]);
    }

    /** Shows the answer of «Why?» that `explain()` stored. */
    public function explanationAction(): Action
    {
        return ExplainAction::answer(fn (): ?array => $this->explanation)
            ->authorize(static fn (): bool => static::getResource()::can('view'));
    }

    /**
     * The editor of the panel and the tenant of the filter, after the target is checked again; null until a panel, and a
     * tenant where the editor chooses one, are named.
     */
    protected function editor(?string $panel = null, ?string $tenant = null, bool $fromFilter = true): ?GrantEditor
    {
        if ($fromFilter) {
            $panel = $this->filter('panel');
            $tenant = $this->filter('tenant');
        }
        $selector = self::selector();

        if ($panel === null || $selector->choosesTenant($panel) && $tenant === null) {
            return null;
        }

        return $this->editors[$panel."\0".$tenant] ??= GrantEditor::of($selector->access($panel, $tenant), static::kind());
    }

    /**
     * One page of the grants of the filter; the cursor of a page was stored when the page before it was read, and a
     * change of the filter starts again from the first page.
     *
     * @return Paginator<string, array<string, mixed>>
     */
    private function grantPage(int $page, int $recordsPerPage): Paginator
    {
        $editor = $this->editor();
        $filter = $editor === null ? null : $this->grantFilter($editor, max(1, min($recordsPerPage, GrantFilter::MAX_LIMIT)));

        if ($editor === null || $filter === null) {
            return new Paginator([], max(1, $recordsPerPage), $page);
        }
        $digest = hash('sha256', serialize([$this->filter('panel'), $this->filter('tenant'), $filter->conditions(), $filter->limit]));

        if ($digest !== $this->grantCursorFilter) {
            $this->grantCursors = [];
            $this->grantCursorFilter = $digest;
        }
        $cursor = $page > 1 ? ($this->grantCursors[$page] ?? null) : null;

        if ($page > 1 && $cursor === null) {
            return new Paginator([], $filter->limit, $page);
        }

        try {
            $result = $editor->page($filter->after($cursor));
        } catch (InvalidArgumentException) {
            // A cursor of another filter or code build is refused by the manager: start again from the first page.
            $this->grantCursors = [];

            return new Paginator([], $filter->limit, $page);
        }

        if ($result->nextCursor !== null) {
            $this->grantCursors[$page + 1] = $result->nextCursor;
        }

        return new Paginator(self::rows($editor, $result), $filter->limit, $page);
    }

    /**
     * The rows of a page by grant id; when a next page exists, one more item tells the paginator so and is never shown.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function rows(GrantEditor $editor, GrantPage $page): array
    {
        $rows = [];

        foreach ($page->items as $record) {
            $rows[$record->id] = $editor->row($record);
        }

        if ($page->nextCursor !== null) {
            $rows["\0next"] = ['id' => "\0next"];
        }

        return $rows;
    }

    /** The grant filter of the table filters; null when a value names nothing of the target. */
    private function grantFilter(GrantEditor $editor, int $limit): ?GrantFilter
    {
        $panel = $editor->access->definition()->id();
        $key = $this->filter('key');
        $subject = $this->filter('subject');
        $type = $this->filter('context_type');
        $state = $this->filter('state') ?? GrantFilter::ACTIVE;

        $contextId = $this->filter('context');

        try {
            $context = match (true) {
                $type === GrantEditor::TENANT_WIDE => AssignmentScopeRef::global(),
                $type === null || $contextId === null => null,
                default => $editor->context($type, $contextId) ?? throw new InvalidArgumentException('unknown context'),
            };

            return new GrantFilter(
                kind: static::kind(),
                subject: $subject === null ? null : ($editor->subject($subject) ?? throw new InvalidArgumentException('unknown subject')),
                context: $context,
                role: static::kind() === 'role' && $key !== null ? RoleKey::of($panel, $key) : null,
                permission: static::kind() === 'permission' && $key !== null ? PermissionPattern::of($panel, $key) : null,
                state: $state,
                limit: $limit,
                expiresBefore: self::moment($this->filter('expires_before')),
                grantedBy: $this->actor($this->filter('granted_by')),
            );
        } catch (InvalidArgumentException|InvalidIdentityException|AzGuardException) {
            return null;
        }
    }

    /**
     * @return list<mixed>
     */
    private function filterSchema(string $label): array
    {
        $clearTarget = static function (Set $set): void {
            foreach (['tenant', 'key', 'subject', 'context_type', 'context', 'granted_by'] as $field) {
                $set($field, null);
            }
        };

        return [
            Select::make('panel')->label('Panel')
                ->options(static fn (): array => self::selector()->panels())
                ->default(static fn (): ?string => self::selector()->defaultPanel())
                ->selectablePlaceholder(false)
                ->live()
                ->afterStateUpdated($clearTarget),
            Select::make('tenant')->label('Tenant')
                ->searchable()
                ->getSearchResultsUsing(static fn (Get $get, ?string $search): array => self::selector()->searchTenants($get('panel'), (string) $search))
                ->getOptionLabelUsing(static fn (Get $get, ?string $value): ?string => self::selector()->tenantLabel($get('panel'), $value))
                ->visible(static fn (Get $get): bool => self::selector()->choosesTenant($get('panel')))
                ->live()
                ->afterStateUpdated(static function (Set $set): void {
                    foreach (['subject', 'context', 'granted_by'] as $field) {
                        $set($field, null);
                    }
                }),
            Select::make('key')->label($label)
                ->options(fn (): array => $this->editor()?->grantable() ?? []),
            Select::make('subject')->label('Subject')
                ->searchable()
                ->getSearchResultsUsing(fn (?string $search): array => $this->editor()?->searchSubjects((string) $search) ?? [])
                ->getOptionLabelUsing(fn (?string $value): ?string => $this->editor()?->subjectLabel($value)),
            Select::make('context_type')->label('Context type')
                ->options(fn (): array => $this->editor() === null ? [] : [GrantEditor::TENANT_WIDE => 'Whole tenant', ...$this->editor()->scopeTypes()])
                ->live()
                ->afterStateUpdated(static fn (Set $set) => $set('context', null)),
            Select::make('context')->label('Context')
                ->searchable()
                ->getSearchResultsUsing(fn (Get $get, ?string $search): array => $this->editor()?->inspectContexts($get('context_type'), (string) $search) ?? [])
                ->getOptionLabelUsing(fn (Get $get, ?string $value): ?string => $this->editor()?->contextLabel($get('context_type'), $value))
                ->visible(static fn (Get $get): bool => filled($get('context_type')) && $get('context_type') !== GrantEditor::TENANT_WIDE),
            Select::make('state')->label('State')
                ->options([GrantFilter::ACTIVE => 'active', GrantFilter::EXPIRED => 'expired', GrantFilter::ORPHANED => 'orphaned', GrantFilter::ANY => 'any'])
                ->default(GrantFilter::ACTIVE)
                ->selectablePlaceholder(false),
            DateTimePicker::make('expires_before')->label('Expires before'),
            Select::make('granted_by')->label('Granted by')
                ->searchable()
                ->getSearchResultsUsing(fn (?string $search): array => ['system' => 'system', ...($this->editor()?->searchSubjects((string) $search) ?? [])])
                ->getOptionLabelUsing(fn (?string $value): ?string => $value === 'system' ? 'system' : $this->editor()?->subjectLabel($value)),
        ];
    }

    private function createAction(string $label): Action
    {
        return Action::make('create')->label('New grant')
            ->visible(fn (): bool => static::getResource()::can('create') && self::selector()->panels() !== [])
            ->fillForm(fn (): array => ['panel' => $this->filter('panel'), 'tenant' => $this->filter('tenant'), 'context_type' => null, 'fields' => []])
            ->schema(fn (): array => $this->grantForm($label))
            ->action(fn (array $data, Schema $schema) => $this->write('create', fn (GrantEditor $editor, Model $user): ChangeResult => $editor->grant($this->submitted($data, $schema), $user), $data, $schema->getStatePath()));
    }

    /**
     * The form of a new grant in the order of the editor: target panel and tenant, subject, role or permission, type of
     * context, context, expiry and the fields. A change of the panel or the tenant clears everything chosen after it.
     *
     * @return list<mixed>
     */
    private function grantForm(string $label): array
    {
        $after = static function (array $fields): Closure {
            return static function (Set $set) use ($fields): void {
                foreach ($fields as $field) {
                    $set($field, $field === 'fields' ? [] : null);
                }
            };
        };
        $editor = fn (Get $get): ?GrantEditor => $this->formEditor($get);

        return [
            Select::make('panel')->label('Panel')->required()
                ->options(static fn (): array => self::selector()->panels())
                ->live()
                ->afterStateUpdated($after(['tenant', 'subject', 'key', 'context_type', 'context', 'fields'])),
            Select::make('tenant')->label('Tenant')
                ->searchable()
                ->getSearchResultsUsing(static fn (Get $get, ?string $search): array => self::selector()->searchTenants($get('panel'), (string) $search))
                ->getOptionLabelUsing(static fn (Get $get, ?string $value): ?string => self::selector()->tenantLabel($get('panel'), $value))
                ->visible(static fn (Get $get): bool => self::selector()->choosesTenant($get('panel')))
                ->required(static fn (Get $get): bool => self::selector()->choosesTenant($get('panel')))
                ->live()
                ->afterStateUpdated($after(['subject', 'key', 'context_type', 'context', 'fields'])),
            Select::make('subject')->label('Subject')->required()
                ->searchable()
                ->getSearchResultsUsing(static fn (Get $get, ?string $search): array => $editor($get)?->searchSubjects((string) $search) ?? [])
                ->getOptionLabelUsing(static fn (Get $get, ?string $value): ?string => $editor($get)?->subjectLabel($value))
                ->live()
                ->afterStateUpdated($after(['context'])),
            Select::make('key')->label($label)->required()
                ->options(static fn (Get $get): array => $editor($get)?->grantable() ?? [])
                ->live()
                ->afterStateUpdated($after(['context_type', 'context'])),
            Select::make('context_type')->label('Context type')->required()
                ->options(static fn (Get $get): array => $editor($get)?->contextTypes($get('key')) ?? [])
                ->live()
                ->afterStateUpdated($after(['context'])),
            Select::make('context')->label('Context')
                ->searchable()
                ->getSearchResultsUsing(static fn (Get $get, ?string $search): array => $editor($get)?->searchContexts(
                    $get('context_type'), (string) $search, $get('subject'), $get('key'), is_array($get('fields')) ? $get('fields') : [],
                ) ?? [])
                ->getOptionLabelUsing(static fn (Get $get, ?string $value): ?string => $editor($get)?->contextLabel($get('context_type'), $value))
                ->visible(static fn (Get $get): bool => filled($get('context_type')) && $get('context_type') !== GrantEditor::TENANT_WIDE)
                ->required(static fn (Get $get): bool => filled($get('context_type')) && $get('context_type') !== GrantEditor::TENANT_WIDE),
            DateTimePicker::make('until')->label('Until'),
            Group::make(static function (Get $get) use ($editor): array {
                $target = $editor($get);

                return $target === null ? [] : SchemaFields::for($target->schema(), $target->fieldTarget(), self::extensions());
            })->columns(2),
        ];
    }

    /** The editor of the panel and the tenant chosen in a form; null while they are not chosen or not offered. */
    private function formEditor(Get $get): ?GrantEditor
    {
        $panel = is_string($get('panel')) && $get('panel') !== '' ? $get('panel') : null;
        $tenant = is_scalar($get('tenant')) && (string) $get('tenant') !== '' ? (string) $get('tenant') : null;

        if ($panel === null || ! array_key_exists($panel, self::selector()->panels())) {
            return null;
        }

        try {
            return $this->editor($panel, $tenant, fromFilter: false);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Fills the edit form of a row and keeps the fingerprint it was filled from.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function editForm(array $record): array
    {
        $editor = $this->editor();
        $id = (string) ($record['id'] ?? '');
        $stored = $editor?->record($id);
        abort_if($editor === null || $stored === null, 403);
        $this->editing = ['id' => $id, 'fingerprint' => $stored->fingerprint];

        return [
            'until' => $stored->until?->format('Y-m-d H:i:s'),
            'fields' => SchemaFields::state($editor->schema(), $editor->fieldTarget(), $stored->fields),
        ];
    }

    /** The fingerprint the open edit form of the grant was filled from; null refuses nothing and is never used. */
    private function editingFingerprint(string $id): string
    {
        $editing = $this->editing;
        abort_if($editing === null || $editing['id'] !== $id, 403, 'The edit form of this grant is not open.');

        return $editing['fingerprint'];
    }

    /**
     * @return list<mixed>
     */
    private function fieldComponents(): array
    {
        $editor = $this->editor();

        return $editor === null ? [] : SchemaFields::for($editor->schema(), $editor->fieldTarget(), self::extensions());
    }

    /**
     * The ids of the selected rows as the client sent them: an id that is not on the page is not dropped, so a grant of
     * another tenant refuses the whole revocation.
     *
     * @return list<mixed>
     */
    private function selectedGrantIds(): array
    {
        if (! $this->isTrackingDeselectedTableRecords) {
            return array_values($this->selectedTableRecords);
        }
        $records = $this->getTableRecords();
        $deselected = array_map(strval(...), $this->deselectedTableRecords);
        $ids = [];

        foreach (array_keys($records instanceof Collection ? $records->all() : $records->items()) as $key) {
            if ($key !== "\0next" && ! in_array((string) $key, $deselected, true)) {
                $ids[] = (string) $key;
            }
        }

        return $ids;
    }

    /**
     * Asks the panel why the subject has the permission, or not, and opens the answer.
     *
     * @param  array<string, mixed>  $data
     */
    private function explain(array $data): void
    {
        abort_unless(static::getResource()::can('view'), 403);
        $editor = $this->editor() ?? abort(403);

        try {
            $explanation = $editor->explain(self::text($data['subject'] ?? null), self::text($data['permission'] ?? null),
                self::text($data['context_type'] ?? null), self::text($data['context'] ?? null));
        } catch (ValidationException|AzGuardException $error) {
            Notification::make()->danger()->title('No answer')->body($error->getMessage())->send();

            return;
        }
        $context = self::text($data['context_type'] ?? null);
        $question = implode(' · ', [
            $editor->subjectLabel(self::text($data['subject'] ?? null)) ?? '',
            (string) self::text($data['permission'] ?? null),
            $context === null || $context === GrantEditor::TENANT_WIDE ? 'whole tenant' : ($editor->contextLabel($context, self::text($data['context'] ?? null)) ?? ''),
        ]);
        $this->explanation = ExplainAction::describe($explanation, $question);
        $this->replaceMountedAction('explanation');
    }

    /**
     * Writes as the user who edits after the permission of the editor and the target are checked again; a refusal of a
     * check or of the writer is shown and nothing is written. Fields the writer refuses are errors of the open form,
     * which stays open.
     *
     * @param  Closure(GrantEditor, Model): ChangeResult  $change
     * @param  array<string, mixed>|null  $data  the form of a new grant, which names its own target
     * @param  string|null  $statePath  the state path of the open form
     *
     * @throws ValidationException when the writer refuses the fields of the open form
     */
    private function write(string $ability, Closure $change, ?array $data = null, ?string $statePath = null): void
    {
        abort_unless(static::getResource()::can($ability), 403);
        $editor = $data === null ? ($this->editor() ?? abort(403))
            : ($this->editor(self::text($data['panel'] ?? null), self::text($data['tenant'] ?? null), fromFilter: false) ?? abort(403));
        $user = Filament::auth()->user();
        abort_unless($user instanceof Model, 403);

        try {
            $change($editor, $user);
        } catch (ValidationException $error) {
            $this->editing = null;
            Notification::make()->danger()->title('The grant was not saved')->body($error->validator->errors()->first())->send();

            return;
        } catch (AzGuardException $error) {
            if ($error instanceof InvalidChangeFieldsException && $statePath !== null) {
                throw ValidationException::withMessages(self::fieldErrors($error, $statePath));
            }
            $this->editing = null;
            Notification::make()->danger()->title('The grant was not saved')->body($error->getMessage())->send();

            return;
        }
        $this->editing = null;
        Notification::make()->success()->title('Saved')->send();
        // The filters stay; the pages start again, because a change moves the grants between them.
        $this->grantCursors = [];
        $this->deselectAllTableRecords();
        $this->resetPage($this->getTablePaginationPageName());
        $this->flushCachedTableRecords();
    }

    /**
     * The data of the open form with its grant fields as they were submitted: the values Filament validated and every
     * other key of the payload under the fields, which Filament would drop and the writer refuses.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function submitted(array $data, Schema $schema): array
    {
        $fields = is_array($data[SchemaFields::PATH] ?? null) ? $data[SchemaFields::PATH] : [];
        $payload = data_get($this, $schema->getStatePath().'.'.SchemaFields::PATH);

        return [...$data, SchemaFields::PATH => is_array($payload) ? $fields + $payload : $fields];
    }

    /**
     * The errors of the writer at the fields of the open form: the expiry at its own field, every other name under the
     * grant fields.
     *
     * @return array<string, list<string>>
     */
    private static function fieldErrors(InvalidChangeFieldsException $error, string $statePath): array
    {
        $messages = [];

        foreach ($error->errors() as $name => $errors) {
            $path = $name === 'until' ? 'until' : SchemaFields::PATH.'.'.$name;
            $messages[$statePath.'.'.$path] = $errors;
        }

        return $messages;
    }

    /**
     * The form extensions of the plugin of the current Filament panel, resolved for this form.
     *
     * @return list<FilamentFormExtension>
     */
    private static function extensions(): array
    {
        return AzGuardPlugin::get()->resolveFormExtensions();
    }

    private function actor(?string $value): ?ActorRef
    {
        if ($value === null) {
            return null;
        }

        if ($value === 'system') {
            return ActorRef::system();
        }
        $parts = explode(':', $value, 2);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException('unknown actor');
        }

        return ActorRef::of($parts[0], $parts[1]);
    }

    private function filter(string $name): ?string
    {
        $value = $this->tableFilters['grants'][$name] ?? null;

        if ($name === 'panel' && ($value === null || $value === '')) {
            return self::selector()->defaultPanel();
        }

        return self::text($value);
    }

    private static function selector(): TargetSelector
    {
        return TargetSelector::current();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function fieldsText(array $fields): ?string
    {
        if ($fields === []) {
            return null;
        }
        $parts = [];

        foreach ($fields as $name => $value) {
            $parts[] = $name.': '.(is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value));
        }

        return implode(', ', $parts);
    }

    private static function moment(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new InvalidArgumentException('not a moment');
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
