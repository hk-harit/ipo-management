<x-filament-panels::page>
    @php
        $blockedApps = $this->blockedApplications();
        $totalBlocked = $record->blocked_in_ipos;
        $availBalance = $record->current_balance;
        $totalBalance = $record->total_balance;
    @endphp

    {{-- Sub-Account Parent / Children Banner --}}
    @if ($record->is_sub_account && $record->parent)
        <div class="flex items-center justify-between p-3.5 rounded-2xl bg-primary-50/60 dark:bg-primary-950/40 border border-primary-200 dark:border-primary-800/40 mb-2">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-primary-100 text-primary-800 dark:bg-primary-900/60 dark:text-primary-300">
                    Sub-Account
                </span>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    Parent Main Account: <strong>{{ $record->parent->name }}</strong>
                </span>
            </div>
            <a
                href="{{ \App\Filament\Resources\AccountResource::getUrl('passbook', ['record' => $record->parent]) }}"
                class="text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline inline-flex items-center gap-1"
            >
                <span>View Main Account Passbook</span>
                <x-heroicon-m-arrow-right class="w-3.5 h-3.5" />
            </a>
        </div>
    @elseif ($record->subAccounts->isNotEmpty())
        <div class="p-4 rounded-2xl bg-white dark:bg-gray-900 border border-gray-200/80 dark:border-white/10 shadow-xs mb-2">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <x-heroicon-m-rectangle-stack class="w-5 h-5 text-primary-600" />
                    <h4 class="text-sm font-bold text-gray-950 dark:text-white">
                        Sub-Accounts Under This Account
                    </h4>
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                        {{ $record->subAccounts->count() }} Sub-Accounts
                    </span>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($record->subAccounts as $sub)
                    <div class="p-3 rounded-xl bg-gray-50 dark:bg-white/[0.03] border border-gray-200/60 dark:border-white/5 flex items-center justify-between">
                        <div>
                            <div class="font-bold text-xs text-gray-900 dark:text-white">{{ $sub->name }}</div>
                            <div class="text-[11px] text-gray-500 font-mono mt-0.5">
                                Avail: <span class="font-bold text-emerald-600">₹{{ number_format($sub->current_balance, 2) }}</span>
                                @if ($sub->blocked_in_ipos > 0)
                                    · Blocked: <span class="font-bold text-amber-600">₹{{ number_format($sub->blocked_in_ipos, 2) }}</span>
                                @endif
                            </div>
                        </div>
                        <a
                            href="{{ \App\Filament\Resources\AccountResource::getUrl('passbook', ['record' => $sub]) }}"
                            class="p-1.5 rounded-lg bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:text-primary-600 shadow-2xs border border-gray-200/60 dark:border-white/10"
                            title="View Sub-Account Passbook"
                        >
                            <x-heroicon-m-arrow-top-right-on-square class="w-4 h-4" />
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 4 Metric Summary Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- Total Balance --}}
        <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-gray-900 border border-gray-200/80 dark:border-white/10 p-5 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Total Capital
                </span>
                <div class="p-2 rounded-xl bg-primary-50 dark:bg-primary-950/50 text-primary-600 dark:text-primary-400">
                    <x-heroicon-m-banknotes class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 text-2xl font-extrabold text-gray-950 dark:text-white font-mono">
                ₹{{ number_format($totalBalance, 2) }}
            </div>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Liquid Balance + Blocked in IPOs
            </p>
        </div>

        {{-- Blocked in IPOs --}}
        <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-gray-900 border {{ $totalBlocked > 0 ? 'border-amber-300 dark:border-amber-500/40 bg-amber-50/20' : 'border-gray-200/80 dark:border-white/10' }} p-5 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider {{ $totalBlocked > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-gray-500 dark:text-gray-400' }}">
                    Blocked in IPOs
                </span>
                <div class="p-2 rounded-xl {{ $totalBlocked > 0 ? 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300' : 'bg-gray-100 dark:bg-white/10 text-gray-500' }}">
                    <x-heroicon-m-lock-closed class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 text-2xl font-extrabold font-mono {{ $totalBlocked > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-gray-300' }}">
                ₹{{ number_format($totalBlocked, 2) }}
            </div>
            <p class="mt-1 text-xs {{ $totalBlocked > 0 ? 'text-amber-700 dark:text-amber-400 font-medium' : 'text-gray-500 dark:text-gray-400' }}">
                {{ $blockedApps->count() }} application(s) currently lien blocked
            </p>
        </div>

        {{-- Available Right Now --}}
        <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-gray-900 border border-gray-200/80 dark:border-white/10 p-5 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Available Free
                </span>
                <div class="p-2 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                    <x-heroicon-m-check-badge class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 text-2xl font-extrabold font-mono {{ $availBalance < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                ₹{{ number_format($availBalance, 2) }}
            </div>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Free balance to apply for new IPOs
            </p>
        </div>

        {{-- Opening Balance --}}
        <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-gray-900 border border-gray-200/80 dark:border-white/10 p-5 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Opening Balance
                </span>
                <div class="p-2 rounded-xl bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-400">
                    <x-heroicon-m-building-library class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-2 text-2xl font-extrabold text-gray-950 dark:text-white font-mono">
                ₹{{ number_format($record->opening_balance, 2) }}
            </div>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate">
                {{ $record->type === 'bank' ? ($record->bank_name ?: 'Bank Account') : 'Cash' }}
                @if ($record->holder_name)
                    · {{ $record->holder_name }}
                @endif
            </p>
        </div>
    </div>

    {{-- Blocked IPOs Section --}}
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-m-lock-closed class="w-5 h-5 text-amber-500" />
                <span>Currently Blocked in IPOs</span>
                @if ($blockedApps->isNotEmpty())
                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                        {{ $blockedApps->count() }} Pending
                    </span>
                @endif
            </div>
        </x-slot>
        <x-slot name="description">
            ASBA / UPI mandate amounts currently on hold in this bank account until allotment announcement.
        </x-slot>

        @if ($blockedApps->isNotEmpty())
            <div class="overflow-x-auto rounded-xl border border-gray-200/80 dark:border-white/10">
                <table class="w-full text-left text-sm divide-y divide-gray-200 dark:divide-white/10">
                    <thead class="bg-gray-50/80 dark:bg-white/[0.03] text-gray-600 dark:text-gray-400 font-semibold uppercase text-[11px] tracking-wider">
                        <tr>
                            <th class="py-3 px-4">Applied Date</th>
                            <th class="py-3 px-4">IPO Name</th>
                            <th class="py-3 px-4">Applicant</th>
                            <th class="py-3 px-4 text-right">Blocked Amount</th>
                            <th class="py-3 px-4 text-center">Status</th>
                            <th class="py-3 px-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5 bg-white dark:bg-gray-900">
                        @foreach ($blockedApps as $app)
                            <tr class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02] transition">
                                <td class="py-3 px-4 whitespace-nowrap text-gray-600 dark:text-gray-400">
                                    {{ $app->applied_date?->format('d M Y') ?? '—' }}
                                </td>
                                <td class="py-3 px-4">
                                    <div class="font-bold text-gray-950 dark:text-white">
                                        {{ $app->ipo?->name ?? '—' }}
                                    </div>
                                    @if ($app->ipo?->symbol || $app->ipo?->issue_type)
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                            {{ $app->ipo?->symbol }}
                                            @if ($app->ipo?->issue_type)
                                                · <span class="font-semibold">{{ $app->ipo->issue_type === 'SME' ? 'SME' : 'Mainboard' }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-gray-800 dark:text-gray-200">
                                    <div class="font-medium">{{ $app->applicant?->name ?? '—' }}</div>
                                    @if ($app->applicant?->relation)
                                        <div class="text-xs text-gray-500 capitalize">{{ $app->applicant->relation }}</div>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-right font-extrabold text-amber-600 dark:text-amber-400 font-mono whitespace-nowrap">
                                    ₹{{ number_format($app->amount, 2) }}
                                </td>
                                <td class="py-3 px-4 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800/40">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                        Lien Blocked
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            wire:click="unblockApplication({{ $app->id }})"
                                            wire:confirm="Are you sure the mandate is released? ₹{{ number_format($app->amount, 2) }} will be credited back to {{ $record->name }}."
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-semibold bg-amber-500/10 text-amber-700 dark:text-amber-300 hover:bg-amber-500/20 border border-amber-300/60 dark:border-amber-700/50 transition cursor-pointer"
                                            title="Unblock mandate & credit refund to account"
                                        >
                                            <x-heroicon-m-arrow-uturn-left class="w-3.5 h-3.5" />
                                            <span>Unblock / Refund</span>
                                        </button>
                                        <a
                                            href="{{ \App\Filament\Resources\IpoApplicationResource::getUrl('edit', ['record' => $app]) }}"
                                            class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline"
                                        >
                                            <span>View</span>
                                            <x-heroicon-m-arrow-top-right-on-square class="w-3.5 h-3.5" />
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-white/[0.04] border-t border-gray-200 dark:border-white/10 font-bold text-xs sm:text-sm">
                        <tr>
                            <td colspan="3" class="py-3 px-4 text-gray-900 dark:text-white">
                                Total Currently Blocked in "{{ $record->name }}"
                            </td>
                            <td class="py-3 px-4 text-right font-mono text-amber-600 dark:text-amber-400 text-base">
                                ₹{{ number_format($blockedApps->sum('amount'), 2) }}
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <div class="py-8 text-center rounded-xl border border-dashed border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-white/[0.01]">
                <div class="w-10 h-10 mx-auto rounded-full bg-emerald-100 dark:bg-emerald-950/60 flex items-center justify-center text-emerald-600 mb-2">
                    <x-heroicon-m-check class="w-6 h-6" />
                </div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                    No Blocked Funds
                </h4>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    There are no pending IPO applications blocking money in this account. All available balance is free.
                </p>
            </div>
        @endif
    </x-filament::section>

    {{-- Passbook / Ledger Section --}}
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-m-book-open class="w-5 h-5 text-primary-600 dark:text-primary-400" />
                <span>Account Passbook & Ledger</span>
            </div>
        </x-slot>
        <x-slot name="description">
            Complete running ledger of every transaction that has touched "{{ $record->name }}", sorted chronologically.
        </x-slot>

        <div class="overflow-x-auto rounded-xl border border-gray-200/80 dark:border-white/10">
            <table class="w-full text-sm divide-y divide-gray-200 dark:divide-white/10">
                <thead class="bg-gray-50/80 dark:bg-white/[0.03] text-gray-600 dark:text-gray-400 font-semibold uppercase text-[11px] tracking-wider">
                    <tr>
                        <th class="py-3 px-4">Date</th>
                        <th class="py-3 px-4">Description</th>
                        <th class="py-3 px-4 text-right">Debit (−)</th>
                        <th class="py-3 px-4 text-right">Credit (+)</th>
                        <th class="py-3 px-4 text-right">Running Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5 bg-white dark:bg-gray-900">
                    @forelse ($this->ledger() as $entry)
                        <tr class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02] transition">
                            <td class="py-2.5 px-4 whitespace-nowrap text-gray-600 dark:text-gray-400">
                                {{ \Illuminate\Support\Carbon::parse($entry['date'])->format('d M Y') }}
                            </td>
                            <td class="py-2.5 px-4 text-gray-950 dark:text-white font-medium">
                                {{ $entry['description'] }}
                            </td>
                            <td class="py-2.5 px-4 text-right text-rose-600 dark:text-rose-400 font-mono">
                                @if ($entry['debit'] > 0)
                                    −₹{{ number_format($entry['debit'], 2) }}
                                @else
                                    <span class="text-gray-300 dark:text-gray-700">—</span>
                                @endif
                            </td>
                            <td class="py-2.5 px-4 text-right text-emerald-600 dark:text-emerald-400 font-mono">
                                @if ($entry['credit'] > 0)
                                    +₹{{ number_format($entry['credit'], 2) }}
                                @else
                                    <span class="text-gray-300 dark:text-gray-700">—</span>
                                @endif
                            </td>
                            <td class="py-2.5 px-4 text-right font-bold text-gray-950 dark:text-white font-mono">
                                ₹{{ number_format($entry['balance'], 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-gray-500 dark:text-gray-400">
                                No transactions recorded yet for this account.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
