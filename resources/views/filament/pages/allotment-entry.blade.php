<x-filament-panels::page>
    @php
        $ipo = $this->selectedIpo();
        $tally = $this->tally();
        $money = fn (float $v) => \App\Services\SourceShare::inr($v);
    @endphp

    <div class="flex flex-wrap items-center gap-3">
        <label for="ipo-picker" class="text-sm font-medium text-gray-700 dark:text-gray-300">IPO</label>
        <select id="ipo-picker" wire:model.live="ipo"
                class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
            @foreach ($this->ipoOptions() as $option)
                <option value="{{ $option['id'] }}">{{ $option['name'] }} — {{ $option['apps'] }} apps</option>
            @endforeach
        </select>
        @if ($ipo)
            <span class="text-sm text-gray-500 dark:text-gray-400">
                @if ($ipo->lot_size) 1 lot = {{ $ipo->lot_size }} shares @endif
                @if ($ipo->allotment_date) · allotment {{ $ipo->allotment_date->format('d M') }} @endif
                · registrar: {{ $ipo->registrar ?: 'not set' }}
            </span>
        @endif
    </div>

    @if (! $ipo || count($rows) === 0)
        <x-filament::section>
            <div class="py-6 text-center text-gray-500 dark:text-gray-400">Aa IPO ma result nakhva jevi koi application nathi.</div>
        </x-filament::section>
    @else
        {{-- Step 1: paste the allotted PANs --}}
        <x-filament::section heading="1. Allotted PAN paste karo" description="KFin / NSE / BSE par je PAN allotted aavya te ahiya paste karo (ek line ma ek, ya vachche space — badhu chalse). Te badha 'Allotted' marks thase.">
            <textarea wire:model="paste" rows="4" placeholder="ABCDE1234F&#10;PQRST5678U"
                      class="w-full rounded-lg border-gray-300 bg-white font-mono text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"></textarea>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-filament::button wire:click="applyPaste" icon="heroicon-o-check-circle" color="success">Allotted marks karo</x-filament::button>
                <x-filament::button wire:click="markRestRefunded" icon="heroicon-o-arrow-uturn-left" color="gray">Baki badha "Not allotted" karo</x-filament::button>
            </div>
        </x-filament::section>

        {{-- Step 2: check and fix --}}
        <x-filament::section heading="2. Check karo ane save karo">
            <div class="mb-3 flex flex-wrap gap-2 text-sm">
                <span class="rounded-full bg-success-50 px-3 py-1 font-semibold text-success-700 dark:bg-success-500/10 dark:text-success-400">Allotted {{ $tally['allotted'] }}</span>
                <span class="rounded-full bg-gray-100 px-3 py-1 font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-300">Not allotted {{ $tally['refunded'] }}</span>
                <span class="rounded-full bg-warning-50 px-3 py-1 font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">Baki (pending) {{ $tally['pending'] }}</span>
                <span class="px-1 py-1 text-gray-500">of {{ $tally['total'] }}</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-3">Applicant</th>
                            <th class="py-2 pr-3 text-right">Lot · Rakam</th>
                            <th class="py-2 pr-3">Result</th>
                            <th class="py-2">Shares allotted</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($this->applications() as $application)
                            <tr wire:key="row-{{ $application->id }}" x-data="{ result: $wire.entangle('rows.{{ $application->id }}.result') }">
                                <td class="py-2 pr-3">
                                    <div class="font-medium">{{ $application->applicant?->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $application->applicant?->pan_number }} · {{ $application->account?->name }}</div>
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">
                                    {{ $this->lotsFor($application) ?? '?' }} lot
                                    <div class="text-xs text-gray-500">{{ $money((float) $application->amount) }}</div>
                                </td>
                                <td class="py-2 pr-3">
                                    <select x-model="result"
                                            class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
                                            :class="{ 'border-success-500': result === 'allotted' }">
                                        <option value="pending">Baki (pending)</option>
                                        <option value="allotted">Allotted ✔</option>
                                        <option value="refunded">Not allotted</option>
                                    </select>
                                </td>
                                <td class="py-2">
                                    <input type="number" min="1" inputmode="numeric" wire:model="rows.{{ $application->id }}.shares"
                                           x-show="result === 'allotted'" x-cloak placeholder="shares"
                                           class="w-28 rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                <x-filament::button wire:click="save" wire:loading.attr="disabled" icon="heroicon-o-check" size="lg">Save allotment</x-filament::button>
                <span class="ml-3 text-xs text-gray-500 dark:text-gray-400">"Not allotted" = paisa account ma pacha (mandate unblock) — passbook ma refund aavse.</span>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
