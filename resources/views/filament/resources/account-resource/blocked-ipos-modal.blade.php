<div class="space-y-4">
    {{-- Summary Header Card --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3.5 rounded-xl bg-gray-50 dark:bg-white/[0.03] border border-gray-200 dark:border-white/10">
        <div>
            <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                Total Blocked (કુલ બ્લોક રકમ)
            </div>
            <div class="mt-0.5 text-lg font-bold text-amber-600 dark:text-amber-400">
                ₹{{ number_format($account->blocked_in_ipos, 2) }}
            </div>
        </div>
        <div>
            <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                Available Free (ઉપલબ્ધ રકમ)
            </div>
            <div class="mt-0.5 text-lg font-bold text-emerald-600 dark:text-emerald-400">
                ₹{{ number_format($account->current_balance, 2) }}
            </div>
        </div>
        <div>
            <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                Total Account Capital (કુલ રકમ)
            </div>
            <div class="mt-0.5 text-lg font-bold text-gray-900 dark:text-white">
                ₹{{ number_format($account->total_balance, 2) }}
            </div>
        </div>
    </div>

    {{-- Blocked IPOs Table or Empty State --}}
    @if ($blockedApplications->isNotEmpty())
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full text-left text-xs sm:text-sm divide-y divide-gray-200 dark:divide-white/10">
                <thead class="bg-gray-50/80 dark:bg-white/[0.02] text-gray-500 dark:text-gray-400 font-semibold uppercase text-[10px] tracking-wider">
                    <tr>
                        <th class="py-2.5 px-3">IPO Name</th>
                        <th class="py-2.5 px-3">Applicant (ડીમેટ ધારક)</th>
                        <th class="py-2.5 px-3">Applied Date</th>
                        <th class="py-2.5 px-3 text-right">Blocked Amount</th>
                        <th class="py-2.5 px-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5 bg-white dark:bg-gray-900">
                    @foreach ($blockedApplications as $app)
                        <tr class="hover:bg-gray-50/60 dark:hover:bg-white/[0.02] transition">
                            <td class="py-2.5 px-3 font-semibold text-gray-900 dark:text-white">
                                {{ $app->ipo?->name ?? '—' }}
                                @if ($app->ipo?->issue_type)
                                    <span class="ml-1 text-[10px] font-normal px-1.5 py-0.5 rounded bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-300">
                                        {{ $app->ipo->issue_type === 'SME' ? 'SME' : 'EQ' }}
                                    </span>
                                @endif
                            </td>
                            <td class="py-2.5 px-3 text-gray-700 dark:text-gray-300">
                                <span class="font-medium">{{ $app->applicant?->name ?? '—' }}</span>
                                @if ($app->applicant?->relation)
                                    <span class="text-[11px] text-gray-400">({{ ucfirst($app->applicant->relation) }})</span>
                                @endif
                            </td>
                            <td class="py-2.5 px-3 text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                {{ $app->applied_date?->format('d M Y') ?? '—' }}
                            </td>
                            <td class="py-2.5 px-3 text-right font-bold text-amber-600 dark:text-amber-400 whitespace-nowrap font-mono">
                                ₹{{ number_format($app->amount, 2) }}
                            </td>
                            <td class="py-2.5 px-3 text-center whitespace-nowrap">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200/60 dark:border-amber-800/40">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                    Lien Blocked
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50/90 dark:bg-white/[0.03] border-t border-gray-200 dark:border-white/10 font-semibold text-xs">
                    <tr>
                        <td colspan="3" class="py-2.5 px-3 text-gray-700 dark:text-gray-300">
                            Total Blocked ({{ $blockedApplications->count() }} application{{ $blockedApplications->count() > 1 ? 's' : '' }})
                        </td>
                        <td class="py-2.5 px-3 text-right text-amber-600 dark:text-amber-400 font-bold font-mono">
                            ₹{{ number_format($blockedApplications->sum('amount'), 2) }}
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @else
        <div class="py-8 text-center rounded-xl border border-dashed border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-white/[0.01]">
            <x-heroicon-o-check-badge class="w-10 h-10 mx-auto text-emerald-500 mb-2" />
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">કોઈ IPO માં રકમ બ્લોક નથી</h4>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                All funds in "{{ $account->name }}" are currently free and available to use.
            </p>
        </div>
    @endif
</div>
