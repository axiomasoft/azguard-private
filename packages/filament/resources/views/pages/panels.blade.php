<x-filament-panels::page>
    @forelse ($this->panels as $panel)
        <x-filament::section :heading="$panel['label'].' ('.$panel['id'].')'" :description="'Tenants: '.$panel['tenants'].($panel['default'] ? ', default panel' : '').($panel['prefix'] !== null ? ', prefix '.$panel['prefix'] : '')" collapsible>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt class="font-medium">Subjects</dt><dd>{{ implode(', ', $panel['subjects']) ?: '—' }}</dd></div>
                <div><dt class="font-medium">Writer</dt><dd>{{ $panel['writer'] ?? '—' }}@if ($panel['storage'] !== null) (storage {{ $panel['storage'] }})@endif</dd></div>
                <div><dt class="font-medium">Plugins</dt><dd>{{ implode(', ', $panel['plugins']) ?: '—' }}</dd></div>
                <div><dt class="font-medium">Permissions</dt><dd>{{ count($panel['schema']['permissions'] ?? []) }}</dd></div>
                <div><dt class="font-medium">Roles</dt><dd>{{ count($panel['schema']['roles'] ?? []) }}</dd></div>
            </dl>

            <h3 class="mt-4 text-sm font-semibold">Sources</h3>
            <table class="mt-1 w-full text-left text-sm">
                <thead><tr><th>Source</th><th>Label</th><th>Contributes</th><th>Dynamic</th></tr></thead>
                <tbody>
                    @foreach ($panel['sources']['sources'] as $source)
                        <tr>
                            <td>{{ $source['id'] }}</td>
                            <td>{{ $source['label'] }}</td>
                            <td>{{ implode(', ', $source['capabilities']) }}</td>
                            <td>{{ $source['dynamic'] ? 'yes' : 'no' }}</td>
                        </tr>
                    @endforeach
                    @foreach ($panel['sources']['named'] as $named)
                        <tr><td colspan="4">named source {{ $named['name'] }} ({{ $named['origin'] }})</td></tr>
                    @endforeach
                </tbody>
            </table>

            <h3 class="mt-4 text-sm font-semibold">Settings</h3>
            <table class="mt-1 w-full text-left text-sm">
                <thead><tr><th>Setting</th><th>Value</th><th>Origin</th></tr></thead>
                <tbody>
                    @foreach ($panel['settings'] as $setting => $entry)
                        <tr>
                            <td>{{ $setting }}</td>
                            <td>{{ json_encode($entry['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</td>
                            <td>{{ $entry['origin'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @empty
        <x-filament::section>No AzGuard panels are registered.</x-filament::section>
    @endforelse
</x-filament-panels::page>
