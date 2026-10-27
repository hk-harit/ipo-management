<x-filament-panels::page>
    @php
        $data = $this->overview();
        $t = $data['totals'];
        $money = fn (float $v) => ($v < 0 ? '−' : '').'₹'.number_format(abs($v), 2);
        $statusLabel = fn (string $s) => match ($s) {
            'applied' => 'Result awaited',
            'not_allotted' => 'Not allotted (refund pending)',
            'refunded' => 'Not allotted',
            'allotted' => 'Allotted',
            'partial_allotted' => 'Partly allotted',
            'sold' => 'Sold',
            default => $s,
        };
        $focusName = $data['ipo']['name'] ?? 'All IPOs';
    @endphp

    {{-- IPO picker --}}
    <div class="flex flex-wrap items-center gap-3">
        <label for="ipo-picker" class="text-sm font-medium text-gray-700 dark:text-gray-300">IPO (કયો IPO જોવો છે)</label>
        <select id="ipo-picker" wire:model.live="ipo"
                class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
            <option value="all">All IPOs (બધા)</option>
            @foreach ($this->ipoOptions() as $option)
                <option value="{{ $option['id'] }}">{{ $option['name'] }} — {{ $option['apps'] }} apps · ₹{{ number_format($option['total'], 0) }}</option>
            @endforeach
        </select>
    </div>

    {{-- Headline numbers --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Sources e apya (આપેલા)</div>
            <div class="text-xl font-bold text-gray-950 dark:text-white">{{ $money($t['given'] - $t['returned']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Gave {{ $money($t['given']) }} · paid back {{ $money($t['returned']) }}</div>
        </div>
        <div class="rounded-xl border border-primary-500/40 p-4">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Applied — {{ $focusName }}</div>
            <div class="text-xl font-bold text-primary-600 dark:text-primary-400">{{ $money($t['focus_amount']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $t['focus_count'] }} application(s)</div>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Blocked + Demat</div>
            <div class="text-xl font-bold text-warning-600 dark:text-warning-400">{{ $money($t['blocked'] + $t['invested']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Blocked {{ $money($t['blocked']) }} · Demat {{ $money($t['invested']) }}</div>
        </div>
        <div class="rounded-xl border p-4 {{ $t['short_accounts'] > 0 ? 'border-danger-500/60' : 'border-gray-200 dark:border-white/10' }}">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Available (બાકી)</div>
            <div class="text-xl font-bold {{ $t['balance'] < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">{{ $money($t['balance']) }}</div>
            <div class="text-xs {{ $t['short_accounts'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-500 dark:text-gray-400' }}">
                {{ $t['short_accounts'] > 0 ? $t['short_accounts'].' account(s) negative' : 'No account is negative' }}
            </div>
        </div>
    </div>

    {{-- Source-wise --}}
    <x-filament::section heading="Source-wise (કોના કેટલા પૈસા)" description="Darek source e ketla apya, ketla baki, ane {{ $focusName }} ma teni ketli application bhari." collapsible>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-4">Source</th>
                        <th class="py-2 pr-4 text-right">Gave</th>
                        <th class="py-2 pr-4 text-right">Paid back</th>
                        <th class="py-2 pr-4 text-right">Still with you</th>
                        <th class="py-2 pr-4 text-right">Applied ({{ $focusName }})</th>
                        <th class="py-2">From accounts</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($data['sources'] as $row)
                        <tr>
                            <td class="py-2 pr-4 font-semibold">{{ $row['source']->name }}</td>
                            <td class="py-2 pr-4 text-right">{{ $money($row['given']) }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['returned'] > 0 ? $money($row['returned']) : '—' }}</td>
                            <td class="py-2 pr-4 text-right font-semibold {{ $row['due'] > 0 ? 'text-warning-600 dark:text-warning-400' : '' }}">{{ $money($row['due']) }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['focus_count'] }} · {{ $money($row['focus_amount']) }}</td>
                            <td class="py-2 text-gray-600 dark:text-gray-300">{{ $row['focus_accounts'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-center text-gray-500">No source money recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @include('filament.pages.partials.source-applicants')

    {{-- Account-wise --}}
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        @foreach ($data['accounts'] as $row)
            @php($account = $row['account'])
            <div class="rounded-xl border {{ $row['balance'] < 0 ? 'border-danger-500/60' : 'border-gray-200 dark:border-white/10' }} bg-white p-4 shadow-sm dark:bg-white/5 {{ $row['is_sub'] ? 'xl:ml-6' : '' }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <div class="text-base font-bold text-gray-950 dark:text-white">{{ $account->name }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ ucfirst($account->type) }}@if ($account->holder_name) · {{ $account->holder_name }}@endif
                            @if ($row['is_sub']) · Sub-account @endif
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Available</div>
                        <div class="text-lg font-bold {{ $row['balance'] < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">{{ $money($row['balance']) }}</div>
                    </div>
                </div>

                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        <div class="mb-1 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Paisa kon e apya (આવ્યા)</div>
                        @forelse ($row['received'] as $line)
                            <div class="flex justify-between text-sm">
                                <span>{{ $line['source'] }}</span>
                                <span class="font-semibold">{{ $money($line['given']) }}@if ($line['returned'] > 0)<span class="font-normal text-gray-500"> (−{{ $money($line['returned']) }})</span>@endif</span>
                            </div>
                        @empty
                            <div class="text-sm text-gray-500">No source money</div>
                        @endforelse
                        @if ($row['transfers_in'] > 0)
                            <div class="flex justify-between text-sm text-info-600 dark:text-info-400"><span>Transfers in</span><span>+{{ $money($row['transfers_in']) }}</span></div>
                        @endif
                        @if ($row['transfers_out'] > 0)
                            <div class="flex justify-between text-sm text-gray-600 dark:text-gray-300"><span>Transfers out</span><span>−{{ $money($row['transfers_out']) }}</span></div>
                        @endif
                    </div>

                    <div class="rounded-lg bg-primary-50 p-3 dark:bg-primary-500/10">
                        <div class="mb-1 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Applied — {{ $focusName }} (ભર્યા)</div>
                        <div class="text-lg font-bold text-primary-600 dark:text-primary-400">{{ $money($row['focus_amount']) }}</div>
                        <div class="text-xs text-gray-600 dark:text-gray-300">{{ $row['focus_count'] }} application(s)</div>
                        @foreach ($row['focus_by_source'] as $line)
                            <div class="mt-1 flex justify-between text-xs text-gray-600 dark:text-gray-300">
                                <span>{{ $line['source'] }} ({{ $line['count'] }})</span><span>{{ $money($line['amount']) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    <span class="rounded-full bg-warning-50 px-2 py-1 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">Blocked {{ $money($row['blocked']) }}</span>
                    <span class="rounded-full bg-gray-100 px-2 py-1 text-gray-700 dark:bg-white/10 dark:text-gray-300">In demat {{ $money($row['invested']) }}</span>
                    <span class="rounded-full bg-gray-100 px-2 py-1 text-gray-700 dark:bg-white/10 dark:text-gray-300">All IPOs: {{ $row['all_count'] }} apps · {{ $money($row['all_amount']) }}</span>
                </div>

                @if ($row['applications']->isNotEmpty())
                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm font-medium text-primary-600 dark:text-primary-400">
                            Applicants ({{ $row['applications']->count() }})
                        </summary>
                        <div class="mt-2 overflow-x-auto">
                            <table class="w-full text-xs">
                                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                    @foreach ($row['applications'] as $app)
                                        <tr>
                                            <td class="py-1 pr-2">
                                                <div class="font-medium">{{ $app->applicant?->name }}</div>
                                                <div class="text-gray-500">{{ $app->applicant?->pan_number }}@if (! $data['ipo']) · {{ $app->ipo?->name }}@endif</div>
                                            </td>
                                            <td class="py-1 pr-2 text-right font-semibold">{{ $money((float) $app->amount) }}</td>
                                            <td class="py-1 pr-2">{{ $app->source?->name ?? '—' }}@if ((float) $app->applicant_commission_percent > 0)<div class="text-gray-500">applicant {{ rtrim(rtrim(number_format((float) $app->applicant_commission_percent, 2), '0'), '.') }}%</div>@endif</td>
                                            <td class="py-1 text-right text-gray-600 dark:text-gray-300">{{ $statusLabel($app->status) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
