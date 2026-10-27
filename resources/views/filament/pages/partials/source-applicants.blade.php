    {{-- Source -> applicants -> commission --}}
    <x-filament::section heading="Source → Applicants & Commission" description="{{ $focusName }}: for each source's money, who applied, from which account, how much, and each person's share." collapsible>
        <div class="space-y-4">
            @forelse ($data['source_groups'] as $group)
                @php($showProfit = $group['rows']->contains(fn ($r) => $r['profit'] !== null))
                <details class="rounded-lg border border-gray-200 dark:border-white/10" open>
                    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 p-3">
                        <span class="font-bold text-gray-950 dark:text-white">{{ $group['source'] }}</span>
                        <span class="text-sm text-gray-600 dark:text-gray-300">
                            {{ $group['count'] }} apps · <strong>{{ $money($group['amount']) }}</strong>
                            @if ($group['source'] !== 'No source') · source gets {{ rtrim(rtrim(number_format($group['source_pct'], 2), '0'), '.') }}% @endif
                            · applicants: {{ $group['applicant_pct_summary'] }}
                            @if ($group['allotted'] + $group['not_allotted'] > 0)
                                · <strong style="color:#047857;">Allotted {{ $group['allotted'] }}</strong> · <strong style="color:#b91c1c;">Not allotted {{ $group['not_allotted'] }}</strong>@if ($group['waiting']) · pending {{ $group['waiting'] }}@endif
                            @endif
                        </span>
                    </summary>
                    @if ($group['share'])
                        @include('filament.pages.partials.source-share', ['share' => $group['share']])
                    @endif
                    <div class="overflow-x-auto px-3 pb-3">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                                    <th class="py-2 pr-3">Applicant</th>
                                    <th class="py-2 pr-3">Account</th>
                                    <th class="py-2 pr-3 text-right">Lots</th>
                                    <th class="py-2 pr-3 text-right">Amount</th>
                                    <th class="py-2 pr-3 text-right">Source %</th>
                                    <th class="py-2 pr-3 text-right">Applicant %</th>
                                    <th class="py-2 pr-3 text-right">You %</th>
                                    <th class="py-2 pr-3">Result</th>
                                    @if ($showProfit)
                                        <th class="py-2 pr-3 text-right">Profit</th>
                                        <th class="py-2 text-right">Applicant gets</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($group['rows'] as $r)
                                    <tr>
                                        <td class="py-2 pr-3">
                                            <div class="font-medium">{{ $r['applicant'] }}</div>
                                            <div class="text-xs text-gray-500">{{ $r['pan'] }}</div>
                                        </td>
                                        <td class="py-2 pr-3">{{ $r['account'] }}</td>
                                        <td class="py-2 pr-3 text-right">{{ $r['lots'] ?? '—' }}</td>
                                        <td class="py-2 pr-3 text-right">{{ $money($r['amount']) }}</td>
                                        <td class="py-2 pr-3 text-right">{{ rtrim(rtrim(number_format($r['source_pct'], 2), '0'), '.') }}%</td>
                                        <td class="py-2 pr-3 text-right font-semibold {{ $r['applicant_pct'] > 0 ? 'text-info-600 dark:text-info-400' : 'text-gray-400' }}">{{ rtrim(rtrim(number_format($r['applicant_pct'], 2), '0'), '.') ?: '0' }}%</td>
                                        <td class="py-2 pr-3 text-right">{{ rtrim(rtrim(number_format($r['you_pct'], 2), '0'), '.') }}%</td>
                                        <td class="py-2 pr-3 whitespace-nowrap font-semibold" style="color:{{ in_array($r['status'], ['allotted', 'partial_allotted', 'sold'], true) ? '#047857' : '#b91c1c' }};">{{ \App\Services\SourceShare::resultMark($r['status'], $r['shares']) ?: '—' }}</td>
                                        @if ($showProfit)
                                            <td class="py-2 pr-3 text-right">{{ $r['profit'] !== null ? $money($r['profit']) : '' }}</td>
                                            <td class="py-2 text-right font-semibold">{{ $r['applicant_share'] !== null && $r['applicant_pct'] > 0 ? $money($r['applicant_share']) : '' }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @empty
                <div class="py-4 text-center text-gray-500">No applications for this IPO.</div>
            @endforelse
        </div>
    </x-filament::section>
