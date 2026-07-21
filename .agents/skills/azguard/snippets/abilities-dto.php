<?php

// Source: azguard package docs (package: azguard/azguard)

declare(strict_types=1);

namespace App\AzGuard\App\Documents\Abilities;

use App\Models\Document;
use AzGuard\Support\ResolvesGateAbilities;
use Illuminate\Support\Facades\Gate;

/**
 * Abilities DTO — вычисленные boolean-флаги из политик для фронтенда:
 * ответ на вопрос «что можно сделать с ЭТИМ ресурсом прямо сейчас?».
 *
 * Правила:
 *   - DTO не дублирует логику политик — все проверки через Gate::allows();
 *   - передаётся на уровне страницы, не в глобальных shared props;
 *   - поля readonly — вычислены один раз, не мутируются.
 */
final class DocumentsAbilities
{
    use ResolvesGateAbilities;

    public function __construct(
        public readonly bool $canView,
        public readonly bool $canEdit,
        public readonly bool $canDelete,
    ) {}

    public static function fromDocument(Document $document): self
    {
        return new self(
            canView: Gate::allows('app.documents.view', $document),
            canEdit: Gate::allows('app.documents.edit', $document),
            canDelete: Gate::allows('app.documents.delete', $document),
        );
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'canView' => $this->canView,
            'canEdit' => $this->canEdit,
            'canDelete' => $this->canDelete,
        ];
    }
}

// ── Передача в Inertia (на уровне страницы) ─────────────────────────────────
//
// // DocumentController@show
// public function show(Document $document): Response
// {
//     return Inertia::render('Documents/Show', [
//         'document'  => DocumentResource::make($document),
//         'abilities' => DocumentsAbilities::fromDocument($document)->toArray(),
//     ]);
// }
//
// ── Потребление в Vue ───────────────────────────────────────────────────────
//
// <script setup lang="ts">
// const props = defineProps<{
//   document: Document
//   abilities: { canView: boolean; canEdit: boolean; canDelete: boolean }
// }>()
// </script>
//
// <template>
//   <button v-if="abilities.canEdit" @click="edit">Edit</button>
//   <button v-if="abilities.canDelete" @click="destroy">Delete</button>
// </template>
//
// Скаффолдинг: php artisan make:guard-abilities
