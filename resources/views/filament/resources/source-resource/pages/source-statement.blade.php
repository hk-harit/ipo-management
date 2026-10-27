<x-filament-panels::page>
    <style>
        @media print {
            .fi-sidebar, .fi-topbar, .fi-header-actions-ctn, .fi-breadcrumbs, .statement-no-print { display: none !important; }
            .fi-main-ctn, .fi-main { padding: 0 !important; margin: 0 !important; max-width: 100% !important; }
            .fi-section { box-shadow: none !important; break-inside: avoid; }
            body { background: #fff !important; }
        }
    </style>

    @php($summary = $this->summary())
    @php($money = fn (float $v) => '₹'.number_format($v, 2))

    <x-filament::section>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="text-xl font-bold text-gray-950 dark:text-white">{{ $this->record->name }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $this->record->relation }}@if ($this->record->mobile) · {{ $this->record->mobile }}@endif
                </div>
            </div>
            <div class="text-right text-sm text-gray-500 dark:text-gray-400">
                IPO Statement<br>as on {{ now()->format('d M Y') }}
            </div>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ([
                ['You Gave (આપ્યા)', $summary['given'], ''],
                ['Paid Back (પાછા આપ્યા)', $summary['returned'], ''],
                ['Still With Us (બાકી)', $summary['due'], $summary['due'] > 0 ? 'text-warning-600 dark:text-warning-400' : ''],
                ['Profit Earned (નફાનો ભાગ)', $summary['earned'], 'text-info-600 dark:text-info-400'],
                ['Profit Paid (ચૂકવ્યા)', $summary['paid'], ''],
                ['Profit Pending (બાકી)', $summary['pending'], $summary['pending'] > 0 ? 'text-warning-600 dark:text-warning-400' : ''],
            ] as [$label, $value, $class])
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="text-lg font-semibold {{ $class }}">{{ $money($value) }}</div>
                </div>
            @endforeach
            <div class="col-span-2 rounded-lg border-2 border-primary-500 p-3 md:col-span-2">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Total Payable To You (કુલ ચૂકવવાના)</div>
                <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $money($summary['payable']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Money still with us + profit share not yet paid</div>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Money Given & Returned (પાસબુક)" collapsible>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-4">Date</th>
                        <th class="py-2 pr-4">Detail</th>
                        <th class="py-2 pr-4 text-right">Gave (+)</th>
                        <th class="py-2 pr-4 text-right">Paid Back (−)</th>
                        <th class="py-2 text-right">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($this->ledger() as $entry)
                        <tr>
                            <td class="whitespace-nowrap py-2 pr-4">{{ \Illuminate\Support\Carbon::parse($entry['date'])->format('d M Y') }}</td>
                            <td class="py-2 pr-4">{{ $entry['description'] }}</td>
                            <td class="py-2 pr-4 text-right">{{ $entry['given'] > 0 ? $money($entry['given']) : '' }}</td>
                            <td class="py-2 pr-4 text-right">{{ $entry['returned'] > 0 ? $money($entry['returned']) : '' }}</td>
                            <td class="py-2 text-right font-semibold">{{ $money($entry['balance']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-gray-500">No money movement recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Applications Made With Your Money (અરજીઓ)" collapsible>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-4">Applied</th>
                        <th class="py-2 pr-4">IPO</th>
                        <th class="py-2 pr-4">In Name Of</th>
                        <th class="py-2 pr-4 text-right">Amount</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2 pr-4 text-right">Came Back</th>
                        <th class="py-2 pr-4 text-right">Charges</th>
                        <th class="py-2 pr-4 text-right">Profit</th>
                        <th class="py-2 text-right">Your Share</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($this->applications() as $app)
                        <tr>
                            <td class="whitespace-nowrap py-2 pr-4">{{ $app->applied_date->format('d M Y') }}</td>
                            <td class="py-2 pr-4">{{ $app->ipo?->name }}</td>
                            <td class="py-2 pr-4">{{ $app->applicant?->name }}</td>
                            <td class="py-2 pr-4 text-right">{{ $money((float) $app->amount) }}</td>
                            <td class="py-2 pr-4">
                                {{ match ($app->status) {
                                    'applied' => 'Result awaited',
                                    'not_allotted' => 'Not allotted — refund coming',
                                    'refunded' => 'Not allotted',
                                    'allotted' => 'Allotted',
                                    'partial_allotted' => 'Partly allotted',
                                    'sold' => 'Sold',
                                    default => $app->status,
                                } }}
                            </td>
                            <td class="py-2 pr-4 text-right">{{ $app->total_received > 0 ? $money($app->total_received) : '' }}</td>
                            <td class="py-2 pr-4 text-right">{{ $app->sales_charges > 0 ? $money($app->sales_charges) : '' }}</td>
                            <td class="py-2 pr-4 text-right">{{ $app->profit !== null ? $money($app->profit) : '' }}</td>
                            <td class="py-2 text-right font-semibold">
                                @if ($app->source_profit_share !== null)
                                    {{ $money($app->source_profit_share) }}
                                    <span class="block text-xs font-normal text-gray-500">{{ $app->effective_source_share_percent }}%</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-4 text-center text-gray-500">No applications linked to this source yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Profit Share Paid (ચૂકવેલ નફો)" collapsible>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($this->payouts() as $payout)
                    <tr>
                        <td class="whitespace-nowrap py-2 pr-4">{{ $payout->payout_date->format('d M Y') }}</td>
                        <td class="py-2 pr-4">{{ $payout->notes ?: 'Profit share payment' }}</td>
                        <td class="py-2 text-right font-semibold">{{ $money((float) $payout->amount) }}</td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-center text-gray-500">No profit share paid yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Share on WhatsApp" class="statement-no-print">
        <div x-data="{ copied: false, text: @js($this->whatsappText()) }">
            <pre class="whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5" x-text="text"></pre>
            <div class="mt-3 flex gap-2">
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-clipboard-document"
                    x-on:click="navigator.clipboard.writeText(text); copied = true; setTimeout(() => copied = false, 2000)"
                >
                    <span x-show="!copied">Copy text</span>
                    <span x-show="copied" x-cloak>Copied!</span>
                </x-filament::button>
                <x-filament::button
                    tag="a"
                    color="success"
                    icon="heroicon-o-chat-bubble-left-right"
                    x-bind:href="'https://wa.me/?text=' + encodeURIComponent(text)"
                    target="_blank"
                >
                    Open WhatsApp
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
