<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Opening Balance: ₹{{ number_format($record->opening_balance, 2) }}
        </x-slot>
        <x-slot name="description">
            Every transaction that has touched "{{ $record->name }}", in date order, with a running balance.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">Date</th>
                        <th class="py-2 pr-4">Description</th>
                        <th class="py-2 pr-4 text-right">Debit (−)</th>
                        <th class="py-2 pr-4 text-right">Credit (+)</th>
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
                            </td>
                            <td class="py-2 pr-4 text-right text-danger-600 dark:text-danger-400">
                                @if ($entry['debit'] > 0)
                                    ₹{{ number_format($entry['debit'], 2) }}
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-right text-success-600 dark:text-success-400">
                                @if ($entry['credit'] > 0)
                                    ₹{{ number_format($entry['credit'], 2) }}
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-right font-semibold text-gray-950 dark:text-white">
                                ₹{{ number_format($entry['balance'], 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                No transactions yet for this account.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
