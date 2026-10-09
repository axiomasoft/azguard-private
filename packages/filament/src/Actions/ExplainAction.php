<?php

declare(strict_types=1);

namespace AzGuard\Filament\Actions;

use AzGuard\Filament\Editors\GrantEditor;
use AzGuard\Filament\Support\RecordValue;
use AzGuard\Kernel\Decision\Explanation;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * «Why?»: a subject, a permission and a context of the target panel go to `explain()` of the panel, and the answer is
 * shown as the panel gave it: the decision with its reason and the steps of the evaluation, with every value the
 * explanation redacts left redacted.
 *
 * The grant tables show stored grants only; roles given by a rule, grants of relations or of other sources and
 * permissions a policy decides are never rows there, and «Why?» is the way to see them.
 *
 * @internal
 */
final class ExplainAction
{
    public const string NAME = 'why';

    /** The name of the action of a row, which asks about the subject and the context of the row. */
    public const string RECORD = 'why_grant';

    public const string ANSWER = 'explanation';

    public static function make(string $name = self::NAME): Action
    {
        return Action::make($name)->label('Why?')->icon('heroicon-o-question-mark-circle')
            ->modalHeading('Why?')
            ->modalSubmitActionLabel('Explain');
    }

    /**
     * The form of the question for the editor of the target; empty while there is none.
     *
     * @return list<mixed>
     */
    public static function form(?GrantEditor $editor): array
    {
        if ($editor === null) {
            return [];
        }

        return [
            Select::make('subject')->label('Subject')->required()
                ->searchable()
                ->getSearchResultsUsing(static fn (?string $search): array => $editor->searchSubjects((string) $search))
                ->getOptionLabelUsing(static fn (?string $value): ?string => $editor->subjectLabel($value)),
            Select::make('permission')->label('Permission')->required()->searchable()
                ->options($editor->permissions()),
            Select::make('context_type')->label('Context type')
                ->options([GrantEditor::TENANT_WIDE => 'Whole tenant', ...$editor->scopeTypes()])
                ->live()
                ->afterStateUpdated(static fn (Set $set): mixed => $set('context', null)),
            Select::make('context')->label('Context')
                ->searchable()
                ->getSearchResultsUsing(static fn (Get $get, ?string $search): array => $editor->inspectContexts($get->string('context_type', isNullable: true), (string) $search))
                ->getOptionLabelUsing(static fn (Get $get, ?string $value): ?string => $editor->contextLabel($get->string('context_type', isNullable: true), $value))
                ->visible(static fn (Get $get): bool => filled($get('context_type')) && $get('context_type') !== GrantEditor::TENANT_WIDE)
                ->required(static fn (Get $get): bool => filled($get('context_type')) && $get('context_type') !== GrantEditor::TENANT_WIDE),
        ];
    }

    /**
     * The modal with an answer that the server stored.
     *
     * @param  Closure(): (array<string, mixed>|null)  $answer
     */
    public static function answer(Closure $answer): Action
    {
        return Action::make(self::ANSWER)->modalHeading('Why?')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema(static function () use ($answer): array {
                $explanation = $answer() ?? [];
                $decision = is_array($explanation['decision'] ?? null) ? $explanation['decision'] : [];

                return [
                    TextEntry::make('question')->label('Question')->state($explanation['question'] ?? null),
                    TextEntry::make('decision')->label('Decision')->badge()
                        ->state($decision['effect'] ?? null)
                        ->color(static fn (?string $state): string => $state === 'allow' ? 'success' : 'danger'),
                    TextEntry::make('reason')->label('Reason')->state($decision['reason'] ?? null),
                    TextEntry::make('component')->label('Decided by')->state($decision['component'] ?? null)->placeholder('—'),
                    TextEntry::make('message')->label('Message')->state($decision['message'] ?? null)->placeholder('—'),
                    TextEntry::make('steps')->label('Steps')->state($explanation['steps'] ?? [])->listWithLineBreaks()->bulleted()->placeholder('—'),
                ];
            });
    }

    /**
     * The answer as scalars: the question as the editor names it, the decision and the steps that did something, from
     * the redacted snapshot of the explanation.
     *
     * @return array{question: string, decision: array<string, mixed>, steps: list<string>}
     */
    public static function describe(Explanation $explanation, string $question): array
    {
        $snapshot = $explanation->toArray();
        $steps = [];

        foreach ($explanation->steps() as $step) {
            if (($step['outcome'] ?? null) === 'skipped') {
                continue;
            }
            $detail = [];

            foreach (is_array($step['detail'] ?? null) ? $step['detail'] : [] as $name => $value) {
                if ($value !== null && $value !== [] && is_scalar($value)) {
                    $detail[] = $name.'='.$value;
                }
            }
            $component = is_string($step['component'] ?? null) ? ' · '.class_basename($step['component']) : '';
            $steps[] = self::text($step['stage'] ?? null).': '.self::text($step['outcome'] ?? null).' ('.self::text($step['result'] ?? null).')'.$component.($detail === [] ? '' : ' — '.implode(', ', $detail));
        }

        return [
            'question' => $question,
            'decision' => is_array($snapshot['decision'] ?? null) ? RecordValue::map($snapshot['decision'], 'explanation decision') : [],
            'steps' => $steps,
        ];
    }

    /** A trace step keeps its names as strings; anything else (a redacted value) prints as nothing. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
