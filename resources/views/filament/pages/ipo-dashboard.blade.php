<x-filament-panels::page>
    @php
        $report = $this->report();
        $money = fn (float $v) => ($v < 0 ? '−' : '').'₹'.number_format(abs($v), 2);
        $pct = fn (float $v) => rtrim(rtrim(number_format($v, 2), '0'), '.') ?: '0';
        $statusLabel = fn (string $s) => match ($s) {
            'applied' => 'Result awaited (બાકી)',
            'refunded' => 'Not allotted (refund)',
            'allotted' => 'Allotted',
            'partial_allotted' => 'Partly allotted',
            'sold' => 'Sold',
            default => $s,
        };
    @endphp

    {{-- IPO picker --}}
    <div class="flex flex-wrap items-center gap-3">
        <label for="ipo-picker" class="text-sm font-medium text-gray-700 dark:text-gray-300">IPO</label>
        <select id="ipo-picker" wire:model.live="ipo"
                class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
            @foreach ($this->ipoOptions() as $option)
                <option value="{{ $option['id'] }}">{{ $option['name'] }} — {{ $option['apps'] }} apps · ₹{{ number_format($option['total'], 0) }}</option>
            @endforeach
        </select>
    </div>

    @if (! $report)
        <x-filament::section>
            <div class="py-6 text-center text-gray-500 dark:text-gray-400">No application has been made to any IPO yet.</div>
        </x-filament::section>
    @else
        @php
            $ipo = $report['ipo'];
            $data = $report['data'];
            $focusName = $ipo->name;
        @endphp

        {{-- IPO details --}}
        <x-filament::section>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="text-xl font-bold text-gray-950 dark:text-white">{{ $ipo->name }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $ipo->issue_type === 'SME' ? 'SME' : 'Mainboard' }} · {{ ucfirst($ipo->status) }}
                        @if ($ipo->price_band) · {{ $ipo->price_band }} @endif
                        @if ($ipo->lot_size) · lot {{ number_format($ipo->lot_size) }} shares @endif
                        @if ($ipo->lot_amount) · 1 lot ≈ {{ $money($ipo->lot_amount) }} @endif
                    </div>
                </div>
                <div class="text-right text-sm text-gray-600 dark:text-gray-300">
                    @if ($ipo->open_date || $ipo->close_date)
                        <div>{{ $ipo->open_date?->format('d M') }} – {{ $ipo->close_date?->format('d M Y') }}</div>
                    @endif
                    @if ($ipo->allotment_date)<div>Allotment: {{ $ipo->allotment_date->format('d M Y') }}</div>@endif
                    @if ($ipo->listing_date)<div>Listing: {{ $ipo->listing_date->format('d M Y') }}</div>@endif
                </div>
            </div>
            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                @if ($ipo->gmp !== null)
                    <span class="rounded-full bg-success-50 px-2 py-1 text-success-700 dark:bg-success-500/10 dark:text-success-400">GMP ₹{{ number_format((float) $ipo->gmp, 0) }}@if ($ipo->gmp_percent !== null) ({{ number_format((float) $ipo->gmp_percent, 1) }}%)@endif</span>
                @endif
                @if ($ipo->subscription_retail !== null)
                    <span class="rounded-full bg-gray-100 px-2 py-1 text-gray-700 dark:bg-white/10 dark:text-gray-300">Retail {{ number_format((float) $ipo->subscription_retail, 2) }}x</span>
                @endif
                <span class="rounded-full bg-gray-100 px-2 py-1 text-gray-700 dark:bg-white/10 dark:text-gray-300">
                    Registrar: {{ $ipo->registrar ? (\App\Services\AllotmentCheck\AllotmentCheckService::registrarOptions()[$ipo->registrar] ?? $ipo->registrar) : 'not set' }}
                </span>
            </div>
        </x-filament::section>

        {{-- Headline numbers --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div class="rounded-xl border border-primary-500/40 p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Total bharyu (કુલ ભર્યું)</div>
                <div class="text-xl font-bold text-primary-600 dark:text-primary-400">{{ $money($data['totals']['focus_amount']) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Applications</div>
                <div class="text-xl font-bold text-gray-950 dark:text-white">{{ $data['totals']['focus_count'] }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Total lots</div>
                <div class="text-xl font-bold text-gray-950 dark:text-white">{{ $report['total_lots'] }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Different PAN</div>
                <div class="text-xl font-bold text-gray-950 dark:text-white">{{ $report['applicants'] }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Applicant commission</div>
                <div class="text-xl font-bold text-info-600 dark:text-info-400">{{ $report['with_commission'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">applications ma % malshe</div>
            </div>
        </div>

        {{-- Allotment result: who got it --}}
        @php($allot = $report['allotment'])
        <x-filament::section heading="Allotment result — લાગ્યા (કોને લાગ્યું)">
            <div class="mb-3 flex flex-wrap gap-2 text-sm">
                <span class="rounded-full bg-success-50 px-3 py-1 font-bold text-success-700 dark:bg-success-500/10 dark:text-success-400">લાગ્યા {{ $allot['allotted'] }}</span>
                <span class="rounded-full bg-gray-100 px-3 py-1 font-bold text-gray-700 dark:bg-white/10 dark:text-gray-300">ન લાગ્યા {{ $allot['not_allotted'] }}</span>
                <span class="rounded-full bg-warning-50 px-3 py-1 font-bold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">બાકી (result nathi nakhyu) {{ $allot['waiting'] }}</span>
                @if ($allot['allotted'])
                    <span class="px-1 py-1 text-gray-600 dark:text-gray-300">{{ $allot['allotted_shares'] }} shares · cost {{ $money($allot['allotted_amount']) }}</span>
                @endif
            </div>
            @if ($allot['allotted'])
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($allot['rows'] as $r)
                                <tr>
                                    <td class="py-2 pr-3"><div class="font-medium">{{ $r['applicant'] }}</div><div class="text-xs text-gray-500">{{ $r['pan'] }}</div></td>
                                    <td class="py-2 pr-3">{{ $r['source_name'] ?? '—' }}</td>
                                    <td class="py-2 pr-3">{{ $r['account'] }}</td>
                                    <td class="py-2 pr-3 text-right font-semibold text-success-600 dark:text-success-400">{{ $r['shares'] }} shares</td>
                                    <td class="py-2 text-right">{{ $money($r['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-sm text-gray-500 dark:text-gray-400">Haju koi result nakhyo nathi. <strong>Allotment Entry</strong> ma allotted PAN paste karo, pachi ahiya naam dekhase.</div>
            @endif
        </x-filament::section>
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {{-- Lot-wise --}}
            <x-filament::section heading="Lot-wise (કેટલા લોટ)">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-3">Lot</th>
                            <th class="py-2 pr-3 text-right">Applications</th>
                            <th class="py-2 text-right">Rakam</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($report['lots'] as $lot)
                            <tr>
                                <td class="py-2 pr-3 font-semibold">{{ $lot['lots'] ?: '?' }} lot</td>
                                <td class="py-2 pr-3 text-right">{{ $lot['apps'] }}</td>
                                <td class="py-2 text-right">{{ $money($lot['amount']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-bold">
                            <td class="py-2 pr-3">Total</td>
                            <td class="py-2 pr-3 text-right">{{ $data['totals']['focus_count'] }}</td>
                            <td class="py-2 text-right">{{ $money($data['totals']['focus_amount']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </x-filament::section>

            {{-- Status --}}
            <x-filament::section heading="Status (સ્થિતિ)">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($report['statuses'] as $row)
                            <tr>
                                <td class="py-2 pr-3 font-semibold">{{ $statusLabel($row['status']) }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['apps'] }}</td>
                                <td class="py-2 text-right">{{ $money($row['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        </div>

        {{-- Source-wise summary --}}
        <x-filament::section heading="Source-wise (કોના પૈસાથી કેટલું ભર્યું)">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-3">Source</th>
                            <th class="py-2 pr-3 text-right">Applications</th>
                            <th class="py-2 pr-3 text-right">Rakam</th>
                            <th class="py-2 pr-3 text-right">Source %</th>
                            <th class="py-2">Applicant commission</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($data['source_groups'] as $group)
                            <tr>
                                <td class="py-2 pr-3 font-semibold">{{ $group['source'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $group['count'] }}</td>
                                <td class="py-2 pr-3 text-right font-semibold">{{ $money($group['amount']) }}</td>
                                <td class="py-2 pr-3 text-right">{{ $group['source'] === 'No source' ? '—' : $pct($group['source_pct']).'%' }}</td>
                                <td class="py-2 text-gray-600 dark:text-gray-300">{{ $group['applicant_pct_summary'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- Who applied under which source, with commission --}}
        @include('filament.pages.partials.source-applicants')

        {{-- Account-wise (this IPO) --}}
        <x-filament::section heading="Account-wise (કયા ખાતામાંથી)" collapsible>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-3">Account</th>
                            <th class="py-2 pr-3 text-right">Applications</th>
                            <th class="py-2 pr-3 text-right">Bharyu</th>
                            <th class="py-2 text-right">Account ma baki</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($report['accounts'] as $row)
                            <tr>
                                <td class="py-2 pr-3 font-semibold">{{ $row['account']->name }}@if ($row['is_sub']) <span class="text-xs font-normal text-gray-500">(sub)</span>@endif</td>
                                <td class="py-2 pr-3 text-right">{{ $row['focus_count'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $money($row['focus_amount']) }}</td>
                                <td class="py-2 text-right font-semibold {{ $row['balance'] < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">{{ $money($row['balance']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
