<x-filament-panels::page>
    @forelse ($this->severities() as $severity)
        <x-filament::section :heading="ucfirst($severity).'s ('.count($this->severity($severity)).')'">
            <ul class="space-y-2 text-sm">
                @foreach ($this->severity($severity) as $finding)
                    <li>
                        <span class="font-mono">{{ $finding['key'] }}</span>
                        <span class="text-gray-500">{{ $finding['scope'] }}</span>
                        <div>{{ $finding['message'] }}</div>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @empty
        <x-filament::section>AzGuard doctor found no problems.</x-filament::section>
    @endforelse
</x-filament-panels::page>
