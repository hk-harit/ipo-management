@php
    $rows = $this->getRows();
    $totals = $this->getTotals();

    $statusColours = [
        'upcoming' => 'gray',
        'open' => 'info',
        'closed' => 'warning',
        'allotted' => 'success',
        'listed' => 'primary',
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">IPO-wise — where your money went</x-slot>
        <x-slot name="description">
            One row per IPO. "Applied" is money put in with your funds, "Got Shares" is how many
            of those applications were allotted.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-4">IPO</th>
                        <th class="py-2 pr-4">Applied On</th>
                        <th class="py-2 pr-4">In Whose Name</th>
                        <th class="py-2 pr-4 text-right">Applications</th>
                        <th class="py-2 pr-4 text-right">Applied</th>
                        <th class="py-2 pr-4 text-right">Got Shares</th>
                        <th class="py-2 pr-4 text-right">Shares</th>
                        <th class="py-2 pr-4 text-right">Came Back</th>
                        <th class="py-2 pr-4 text-right">Your Share</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="py-2 pr-4">
                                <div class="font-semibold text-gray-950 dark:text-white">{{ $row['ipo'] }}</div>
                                @if ($row['status'])
                                    <x-filament::badge
                                        :color="$statusColours[$row['status']] ?? 'gray'"
                                        size="xs"
                                        class="mt-1 inline-flex"
                                    >
                                        {{ str($row['status'])->headline() }}
                                    </x-filament::badge>
                                @endif
                            </td>
                            <td class="py-2 pr-4 whitespace-nowrap text-gray-600 dark:text-gray-300">
                                {{ $row['applied_date'] ? \Illuminate\Support\Carbon::parse($row['applied_date'])->format('d M Y') : '—' }}
                            </td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">
                                {{ $row['applicants'] ?: '—' }}
                            </td>
                            <td class="py-2 pr-4 text-right text-gray-600 dark:text-gray-300">
                                {{ $row['applications'] }}
                            </td>
                            <td class="py-2 pr-4 text-right font-medium text-gray-950 dark:text-white">
                                ₹{{ number_format($row['applied'], 2) }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                @if ($row['allotted_apps'] > 0)
                                    <span class="font-semibold text-success-600 dark:text-success-400">
                                        {{ $row['allotted_apps'] }} / {{ $row['applications'] }}
                                    </span>
                                @else
                                    <span class="text-gray-400 dark:text-gray-500">
                                        0 / {{ $row['applications'] }}
                                    </span>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-right text-gray-600 dark:text-gray-300">
                                {{ $row['shares_allotted'] ?: '—' }}
                            </td>
                            <td class="py-2 pr-4 text-right text-gray-600 dark:text-gray-300">
                                {{ $row['returned'] > 0 ? '₹'.number_format($row['returned'], 2) : '—' }}
                            </td>
                            <td class="py-2 pr-4 text-right font-semibold {{ $row['my_share'] > 0 ? 'text-info-600 dark:text-info-400' : 'text-gray-400 dark:text-gray-500' }}">
                                {{ $row['my_share'] != 0 ? '₹'.number_format($row['my_share'], 2) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                Nothing has been applied with your money yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($rows->isNotEmpty())
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 font-semibold text-gray-950 dark:border-white/10 dark:text-white">
                            <td class="py-2 pr-4" colspan="4">Total</td>
                            <td class="py-2 pr-4 text-right">₹{{ number_format($totals['applied'], 2) }}</td>
                            <td class="py-2 pr-4"></td>
                            <td class="py-2 pr-4 text-right">{{ $totals['shares_allotted'] ?: '—' }}</td>
                            <td class="py-2 pr-4 text-right">₹{{ number_format($totals['returned'], 2) }}</td>
                            <td class="py-2 pr-4 text-right text-info-600 dark:text-info-400">
                                ₹{{ number_format($totals['my_share'], 2) }}
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
