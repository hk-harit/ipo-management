<x-filament-panels::page>
    @php
        $money = fn (float $v) => \App\Services\SourceShare::inr($v);
        $report = $this->report();
        $listing = $report ? null : $this->listing();
    @endphp

    @if (! $report)
        {{-- ───────────── The list of IPOs ───────────── --}}
        @if ($listing->isEmpty())
            <x-filament::section>
                <div class="py-6 text-center text-gray-500 dark:text-gray-400">No application has been made to any IPO yet.</div>
            </x-filament::section>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($listing as $row)
                    <button type="button" wire:click="openIpo({{ $row['id'] }})" wire:key="ipo-{{ $row['id'] }}"
                            class="rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm transition hover:border-primary-500 hover:shadow-md dark:border-white/10 dark:bg-white/5">
                        <div class="flex items-start justify-between gap-2">
                            <div class="text-base font-bold text-gray-950 dark:text-white">{{ $row['name'] }}</div>
                            @if ($row['ipo'])
                                <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ ucfirst($row['ipo']->status) }}</span>
                            @endif
                        </div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            <strong>{{ $row['apps'] }}</strong> application · <strong>{{ $money($row['amount']) }}</strong>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold">
                            <span class="rounded-full bg-success-50 px-2.5 py-1 text-success-700 dark:bg-success-500/10 dark:text-success-400">Allotted {{ $row['allotted'] }}</span>
                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-gray-700 dark:bg-white/10 dark:text-gray-300">Not allotted {{ $row['not_allotted'] }}</span>
                            @if ($row['refund_pending'])
                                <span class="rounded-full px-2.5 py-1" style="background:#fef2f2; color:#b91c1c;">Refund pending {{ $row['refund_pending'] }} · {{ $money($row['refund_pending_amount']) }}</span>
                            @endif
                            @if ($row['waiting'])
                                <span class="rounded-full bg-warning-50 px-2.5 py-1 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">pending {{ $row['waiting'] }}</span>
                            @endif
                        </div>
                        @if ($row['allotted'])
                            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $row['shares'] }} shares allotted</div>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif
    @else
        {{-- ───────────── One IPO ───────────── --}}
        @php
            $ipo = $report['ipo'];
            $data = $report['data'];
            $allot = $report['allotment'];
            $focusName = $ipo->name;
        @endphp

        <div class="flex flex-wrap items-center justify-between gap-3">
            <button type="button" wire:click="backToList"
                    class="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">
                ← All IPOs
            </button>
            <div class="text-right">
                <div class="text-xl font-bold text-gray-950 dark:text-white">{{ $ipo->name }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ ucfirst($ipo->status) }}
                    @if ($ipo->price_band) · {{ $ipo->price_band }} @endif
                    @if ($ipo->allotment_date) · allotment {{ $ipo->allotment_date->format('d M') }} @endif
                    @if ($ipo->listing_date) · listing {{ $ipo->listing_date->format('d M') }} @endif
                </div>
            </div>
        </div>

        {{-- The four numbers --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-primary-500/40 p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Applied</div>
                <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $data['totals']['focus_count'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">application · {{ $money($data['totals']['focus_amount']) }}</div>
            </div>
            <div class="rounded-xl border border-success-500/40 p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Allotted</div>
                <div class="text-2xl font-bold text-success-600 dark:text-success-400">{{ $allot['allotted'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $allot['allotted_shares'] }} shares · cost {{ $money($allot['allotted_amount']) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Not allotted</div>
                <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ $allot['not_allotted'] }}</div>
                <div class="text-xs">
                    @if ($allot['refund_pending'])<span style="color:#b91c1c; font-weight:600;">Refund pending {{ $allot['refund_pending'] }} · {{ $money($allot['refund_pending_amount']) }}</span>@endif
                    @if ($allot['refund_received'])<span class="text-gray-500 dark:text-gray-400"> · received {{ $allot['refund_received'] }}</span>@endif
                    @if (! $allot['refund_pending'] && ! $allot['refund_received'])<span class="text-gray-500 dark:text-gray-400">—</span>@endif
                    <a href="{{ \App\Filament\Pages\RefundsPage::getUrl() }}" class="text-primary-600 underline"> Refunds</a>
                </div>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Pending (no result yet)</div>
                <div class="text-2xl font-bold {{ $allot['waiting'] ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white' }}">{{ $allot['waiting'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($allot['waiting']) <a href="{{ \App\Filament\Pages\AllotmentEntryPage::getUrl() }}" class="text-primary-600 underline">Allotment Entry</a> to enter the results @else all results are in @endif
                </div>
            </div>
        </div>

        {{-- Account-wise: where applied, where it landed --}}
        <x-filament::section heading="Account-wise — where the allotments landed">
            <div class="space-y-3">
                @foreach ($report['accounts'] as $a)
                    <details class="rounded-lg border border-gray-200 dark:border-white/10" @if ($a['allotted']) open @endif>
                        <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 p-3">
                            <span class="font-bold text-gray-950 dark:text-white">{{ $a['account']->name }}@if ($a['is_sub']) <span class="text-xs font-normal text-gray-500">(sub)</span>@endif</span>
                            <span class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="text-gray-600 dark:text-gray-300">{{ $a['applied'] }} applied · {{ $money($a['applied_amount']) }}</span>
                                <span class="rounded-full bg-success-50 px-2.5 py-0.5 text-xs font-bold text-success-700 dark:bg-success-500/10 dark:text-success-400">Allotted {{ $a['allotted'] }}@if ($a['shares']) · {{ $a['shares'] }} shares @endif</span>
                                <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-bold text-gray-700 dark:bg-white/10 dark:text-gray-300">Not allotted {{ $a['not_allotted'] }}</span>
                                @if ($a['refund_pending'])<span class="rounded-full px-2.5 py-0.5 text-xs font-bold" style="background:#fef2f2; color:#b91c1c;">Refund pending {{ $a['refund_pending'] }} · {{ $money($a['refund_pending_amount']) }}</span>@endif
                                @if ($a['refund_received'])<span class="rounded-full px-2.5 py-0.5 text-xs font-bold" style="background:#f0fdf4; color:#15803d;">Refund received {{ $a['refund_received'] }}</span>@endif
                                @if ($a['waiting'])<span class="rounded-full bg-warning-50 px-2.5 py-0.5 text-xs font-bold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">pending {{ $a['waiting'] }}</span>@endif
                            </span>
                        </summary>
                        <div class="px-3 pb-3 text-sm">
                            @if ($a['allottees']->isNotEmpty())
                                <div class="mb-1 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Who got shares</div>
                                <div class="flex flex-wrap gap-x-4 gap-y-1">
                                    @foreach ($a['allottees'] as $p)
                                        <span>{{ $p['name'] }} <span class="text-success-600 dark:text-success-400">({{ $p['shares'] }})</span></span>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-gray-500 dark:text-gray-400">Nobody got shares in this account.</div>
                            @endif
                            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Balance now: <strong class="{{ $a['balance'] < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $money($a['balance']) }}</strong></div>
                        </div>
                    </details>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Source → applicants (with the WhatsApp share buttons) --}}
        @include('filament.pages.partials.source-applicants')
    @endif
</x-filament-panels::page>
