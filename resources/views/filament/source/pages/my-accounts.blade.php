<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Accounts</x-slot>
        <x-slot name="description">
            Accounts where your funds were received and applied towards IPOs.
        </x-slot>

        {{-- Mobile View (Clean Cards) --}}
        <div class="block md:hidden space-y-3">
            @forelse ($this->accounts() as $row)
                <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-4 shadow-sm">
                    <div class="flex items-center justify-between pb-3 border-b border-gray-100 dark:border-white/5">
                        <div>
                            <div class="font-semibold text-gray-950 dark:text-white text-base">
                                {{ $row['account'] }}
                            </div>
                            @if ($row['holder'])
                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    Held By: {{ $row['holder'] }}
                                </div>
                            @endif
                        </div>
                        <x-filament::badge :color="$row['type'] === 'bank' ? 'info' : 'gray'" size="xs">
                            {{ str($row['type'])->headline() }}
                        </x-filament::badge>
                    </div>

                    <div class="grid grid-cols-3 gap-2 pt-3 text-center">
                        <div class="bg-gray-50 dark:bg-white/5 rounded-lg p-2">
                            <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">You Gave</div>
                            <div class="text-xs sm:text-sm font-bold text-success-600 dark:text-success-400 mt-0.5">
                                {{ $row['you_gave'] > 0 ? '₹'.number_format($row['you_gave'], 2) : '—' }}
                            </div>
                        </div>
                        <div class="bg-gray-50 dark:bg-white/5 rounded-lg p-2">
                            <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">Paid Back</div>
                            <div class="text-xs sm:text-sm font-semibold text-gray-700 dark:text-gray-300 mt-0.5">
                                {{ $row['paid_back'] > 0 ? '₹'.number_format($row['paid_back'], 2) : '—' }}
                            </div>
                        </div>
                        <div class="bg-gray-50 dark:bg-white/5 rounded-lg p-2">
                            <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">Applied</div>
                            <div class="text-xs sm:text-sm font-semibold text-gray-700 dark:text-gray-300 mt-0.5">
                                {{ $row['applied_from'] > 0 ? '₹'.number_format($row['applied_from'], 2) : '—' }}
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-6 text-center text-gray-500 dark:text-gray-400">
                    Your money has not been placed into any account yet.
                </div>
            @endforelse
        </div>

        {{-- Desktop View (Table) --}}
        <div class="hidden md:block overflow-x-auto">
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
