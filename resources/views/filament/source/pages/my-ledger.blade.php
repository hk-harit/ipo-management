@php
    $summary = $this->summary();
@endphp

<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-3">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">You Gave</div>
            <div class="mt-1 text-2xl font-bold text-success-600 dark:text-success-400">
                ₹{{ number_format($summary['given'], 2) }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Paid Back To You</div>
            <div class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">
                ₹{{ number_format($summary['returned'], 2) }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Still With Us</div>
            <div class="mt-1 text-2xl font-bold {{ $summary['due'] > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-success-600 dark:text-success-400' }}">
                ₹{{ number_format($summary['due'], 2) }}
            </div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Passbook</x-slot>

        {{-- Mobile Passbook View --}}
        <div class="block md:hidden divide-y divide-gray-100 dark:divide-white/5">
            @forelse ($this->ledger() as $entry)
                <div class="py-3 flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">
                            {{ \Illuminate\Support\Carbon::parse($entry['date'])->format('d M Y') }}
                        </div>
                        <div class="text-sm font-semibold text-gray-950 dark:text-white mt-0.5">
                            {{ $entry['description'] }}
                        </div>
                        @if ($entry['notes'])
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                {{ $entry['notes'] }}
                            </div>
                        @endif
                    </div>
                    <div class="text-right shrink-0">
                        @if ($entry['given'] > 0)
                            <div class="text-sm font-bold text-success-600 dark:text-success-400">
                                +₹{{ number_format($entry['given'], 2) }}
                            </div>
                        @elseif ($entry['returned'] > 0)
                            <div class="text-sm font-bold text-danger-600 dark:text-danger-400">
                                -₹{{ number_format($entry['returned'], 2) }}
                            </div>
                        @endif
                        <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5 font-mono">
                            Bal: ₹{{ number_format($entry['balance'], 2) }}
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-6 text-center text-gray-500 dark:text-gray-400">
                    No money movement recorded yet.
                </div>
            @endforelse
        </div>

        {{-- Desktop Passbook Table --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-4">Date</th>
                        <th class="py-2 pr-4">Detail</th>
                        <th class="py-2 pr-4 text-right">You Gave (+)</th>
                        <th class="py-2 pr-4 text-right">Paid Back (−)</th>
                        <th class="py-2 pr-4 text-right">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($this->ledger() as $entry)
                        <tr>
                            <td class="py-2 pr-4 whitespace-nowrap text-gray-600 dark:text-gray-300">
                                {{ \Illuminate\Support\Carbon::parse($entry['date'])->format('d M Y') }}
                            </td>
                            <td class="py-2 pr-4 text-gray-950 dark:text-white">
                                {{ $entry['description'] }}
                                @if ($entry['notes'])
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $entry['notes'] }}</div>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-right text-success-600 dark:text-success-400">
                                @if ($entry['given'] > 0)
                                    ₹{{ number_format($entry['given'], 2) }}
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-right text-danger-600 dark:text-danger-400">
                                @if ($entry['returned'] > 0)
                                    ₹{{ number_format($entry['returned'], 2) }}
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-right font-semibold text-gray-950 dark:text-white">
                                ₹{{ number_format($entry['balance'], 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                No money movement recorded yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
