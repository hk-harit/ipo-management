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
                <div class="py-6 text-center text-gray-500 dark:text-gray-400">Haju koi IPO ma application nathi.</div>
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
                            <span class="rounded-full bg-success-50 px-2.5 py-1 text-success-700 dark:bg-success-500/10 dark:text-success-400">લાગ્યા {{ $row['allotted'] }}</span>
                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-gray-700 dark:bg-white/10 dark:text-gray-300">ન લાગ્યા {{ $row['not_allotted'] }}</span>
                            @if ($row['waiting'])
                                <span class="rounded-full bg-warning-50 px-2.5 py-1 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">બાકી {{ $row['waiting'] }}</span>
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
                ← Badha IPO
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
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Apply karyu (ભર્યું)</div>
                <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $data['totals']['focus_count'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">application · {{ $money($data['totals']['focus_amount']) }}</div>
            </div>
            <div class="rounded-xl border border-success-500/40 p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">લાગ્યા</div>
                <div class="text-2xl font-bold text-success-600 dark:text-success-400">{{ $allot['allotted'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $allot['allotted_shares'] }} shares · cost {{ $money($allot['allotted_amount']) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">ન લાગ્યા</div>
                <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ $allot['not_allotted'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">paisa pacha aavya</div>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">બાકી (result nathi)</div>
                <div class="text-2xl font-bold {{ $allot['waiting'] ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white' }}">{{ $allot['waiting'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($allot['waiting']) <a href="{{ \App\Filament\Pages\AllotmentEntryPage::getUrl() }}" class="text-primary-600 underline">Allotment Entry</a> ma nakho @else badha result aavi gaya @endif
                </div>
            </div>
        </div>

        {{-- Account-wise: where applied, where it landed --}}
        <x-filament::section heading="Account-wise — kaya account ma લાગ્યા (કયા ખાતામાં)">
            <div class="space-y-3">
                @foreach ($report['accounts'] as $a)
                    <details class="rounded-lg border border-gray-200 dark:border-white/10" @if ($a['allotted']) open @endif>
                        <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 p-3">
                            <span class="font-bold text-gray-950 dark:text-white">{{ $a['account']->name }}@if ($a['is_sub']) <span class="text-xs font-normal text-gray-500">(sub)</span>@endif</span>
                            <span class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="text-gray-600 dark:text-gray-300">{{ $a['applied'] }} apply · {{ $money($a['applied_amount']) }}</span>
                                <span class="rounded-full bg-success-50 px-2.5 py-0.5 text-xs font-bold text-success-700 dark:bg-success-500/10 dark:text-success-400">લાગ્યા {{ $a['allotted'] }}@if ($a['shares']) · {{ $a['shares'] }} shares @endif</span>
                                <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-bold text-gray-700 dark:bg-white/10 dark:text-gray-300">ન લાગ્યા {{ $a['not_allotted'] }}</span>
                                @if ($a['waiting'])<span class="rounded-full bg-warning-50 px-2.5 py-0.5 text-xs font-bold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">બાકી {{ $a['waiting'] }}</span>@endif
                            </span>
                        </summary>
                        <div class="px-3 pb-3 text-sm">
                            @if ($a['allottees']->isNotEmpty())
                                <div class="mb-1 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Kone કોને લાગ્યા</div>
                                <div class="flex flex-wrap gap-x-4 gap-y-1">
                                    @foreach ($a['allottees'] as $p)
                                        <span>{{ $p['name'] }} <span class="text-success-600 dark:text-success-400">({{ $p['shares'] }})</span></span>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-gray-500 dark:text-gray-400">Aa account ma koine nathi lagyu.</div>
                            @endif
                            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Account ma atyare: <strong class="{{ $a['balance'] < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $money($a['balance']) }}</strong></div>
                        </div>
                    </details>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Source → applicants (with the WhatsApp share buttons) --}}
        @include('filament.pages.partials.source-applicants')
    @endif
</x-filament-panels::page>
