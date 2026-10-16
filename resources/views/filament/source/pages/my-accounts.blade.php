<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Accounts</x-slot>
        <x-slot name="description">
            Accounts where your funds were received and applied towards IPOs.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-4">Account</th>
                        <th class="py-2 pr-4">Held By</th>
                        <th class="py-2 pr-4">Type</th>
                        <th class="py-2 pr-4 text-right">You Gave</th>
                        <th class="py-2 pr-4 text-right">Paid Back</th>
                        <th class="py-2 pr-4 text-right">Applied From Here</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($this->accounts() as $row)
                        <tr>
                            <td class="py-2 pr-4 font-semibold text-gray-950 dark:text-white">
                                {{ $row['account'] }}
                            </td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">
                                {{ $row['holder'] ?: '—' }}
                            </td>
                            <td class="py-2 pr-4">
                                <x-filament::badge :color="$row['type'] === 'bank' ? 'info' : 'gray'" size="xs" class="inline-flex">
                                    {{ str($row['type'])->headline() }}
                                </x-filament::badge>
                            </td>
                            <td class="py-2 pr-4 text-right text-success-600 dark:text-success-400">
                                {{ $row['you_gave'] > 0 ? '₹'.number_format($row['you_gave'], 2) : '—' }}
                            </td>
                            <td class="py-2 pr-4 text-right text-gray-600 dark:text-gray-300">
                                {{ $row['paid_back'] > 0 ? '₹'.number_format($row['paid_back'], 2) : '—' }}
                            </td>
                            <td class="py-2 pr-4 text-right text-gray-600 dark:text-gray-300">
                                {{ $row['applied_from'] > 0 ? '₹'.number_format($row['applied_from'], 2) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                Your money has not been placed into any account yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
