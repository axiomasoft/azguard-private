<x-filament-panels::page>
    @php
        $result = $this->getDiagnoseResult();
        $abilities = $result['abilities'];
        $warnings  = $result['warnings'];
        $errors    = $result['errors'];
    @endphp

    {{-- ── Status ──────────────────────────────────────────────── --}}
    <div class="mb-6">
        @if (count($errors) === 0 && count($warnings) === 0)
            <x-filament::badge color="success" class="text-sm px-3 py-1">
                ✓ All checks passed
            </x-filament::badge>
        @elseif (count($errors) > 0)
            <x-filament::badge color="danger" class="text-sm px-3 py-1">
                ✗ {{ count($errors) }} {{ trans_choice('error|errors', count($errors)) }}, {{ count($warnings) }} {{ trans_choice('warning|warnings', count($warnings)) }}
            </x-filament::badge>
        @else
            <x-filament::badge color="warning" class="text-sm px-3 py-1">
                ⚠ {{ count($warnings) }} {{ trans_choice('warning|warnings', count($warnings)) }}
            </x-filament::badge>
        @endif
    </div>

    {{-- ── Errors ────────────────────────────────────────────── --}}
    @if (count($errors) > 0)
        <x-filament::section
            icon="heroicon-o-x-circle"
            icon-color="danger"
            heading="Consistency errors"
            class="mb-4"
        >
            <ul class="space-y-1">
                @foreach ($errors as $error)
                    <li class="flex items-start gap-2 text-sm text-danger-700 dark:text-danger-400">
                        <x-heroicon-o-x-circle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span class="font-mono">{{ $error }}</span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{-- ── Warnings ──────────────────────────────────────────── --}}
    @if (count($warnings) > 0)
        <x-filament::section
            icon="heroicon-o-exclamation-triangle"
            icon-color="warning"
            heading="Warnings"
            class="mb-4"
        >
            <ul class="space-y-1">
                @foreach ($warnings as $warning)
                    <li class="flex items-start gap-2 text-sm text-warning-700 dark:text-warning-400">
                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span class="font-mono">{{ $warning }}</span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{-- ── Abilities ──────────────────────────────────────────── --}}
    <x-filament::section
        icon="heroicon-o-shield-check"
        icon-color="primary"
        heading="Registered abilities ({{ count($abilities) }})"
        collapsible
        :collapsed="count($abilities) > 20"
    >
        @if (count($abilities) === 0)
            <p class="text-sm text-gray-500">No registered abilities. Check the basePath/namespace configuration.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-4 text-left font-medium text-gray-600 dark:text-gray-400">Panel</th>
                            <th class="py-2 pr-4 text-left font-medium text-gray-600 dark:text-gray-400">Ability</th>
                            <th class="py-2 text-left font-medium text-gray-600 dark:text-gray-400">Policy::method</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($abilities as $row)
                            <tr>
                                <td class="py-1.5 pr-4">
                                    <x-filament::badge color="gray" size="sm">{{ $row['panel'] }}</x-filament::badge>
                                </td>
                                <td class="py-1.5 pr-4 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $row['ability'] }}</td>
                                <td class="py-1.5 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['handler'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
