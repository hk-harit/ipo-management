<x-filament-widgets::widget>
    @php
        $settlements = $this->getSettlements();
        $totalPending = $settlements->where('is_settled', false)->sum('due_amount');
        $activePendingCount = $settlements->where('is_settled', false)->count();
    @endphp

    <div class="space-y-4">
        {{-- Section Header & Quick Stats --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4 rounded-2xl bg-white dark:bg-gray-900 border border-gray-200/80 dark:border-white/10 shadow-xs">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400">
                    <x-heroicon-m-arrow-path-rounded-square class="w-6 h-6" />
                </div>
                <div>
                    <h3 class="text-base font-bold text-gray-950 dark:text-white flex items-center gap-2">
                        <span>Inter-Account Transfer Hisab (ખાતાવાર લેતી-દેતી અને બાકી હિસાબ)</span>
                        @if ($activePendingCount > 0)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                {{ $activePendingCount }} ખાતામાં બાકી
                            </span>
                        @endif
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        કયા ખાતામાંથી કોને કેટલા આપ્યા, કેટલા પાછા આવ્યા અને હજી કયા ખાતામાં કેટલા નાખવાના બાકી છે તે લાઈવ હિસાબ.
                    </p>
                </div>
            </div>

            @if ($totalPending > 0)
                <div class="flex items-center gap-3 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/50 px-4 py-2.5 rounded-xl">
                    <div>
                        <div class="text-[10px] uppercase font-semibold text-amber-700 dark:text-amber-400">
                            કુલ પરત નાખવાના બાકી (Total Due to Transfer Back)
                        </div>
                        <div class="text-lg font-black text-amber-700 dark:text-amber-300 font-mono">
                            ₹{{ number_format($totalPending, 2) }}
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Main Settlement Table / Mobile Cards --}}
        @if ($settlements->isNotEmpty())
            {{-- Mobile Card View (sm:hidden) --}}
            <div class="block sm:hidden space-y-3">
                @foreach ($settlements as $row)
                    <div class="rounded-2xl border border-gray-200/80 dark:border-white/10 bg-white dark:bg-gray-900 p-4 shadow-xs {{ ! $row['is_settled'] ? 'ring-1 ring-amber-400/40 bg-amber-50/10 dark:bg-amber-950/10' : '' }}">
                        <div class="flex items-center justify-between gap-2 pb-2.5 border-b border-gray-100 dark:border-white/5">
                            <div class="flex items-center gap-1.5 min-w-0">
                                <span class="font-bold text-gray-900 dark:text-white truncate text-sm">
                                    {{ $row['giver']->name }}
                                </span>
                                <x-heroicon-m-arrow-right class="w-3.5 h-3.5 text-gray-400 shrink-0" />
                                <span class="font-bold text-gray-900 dark:text-white truncate text-sm">
                                    {{ $row['receiver']->name }}
                                </span>
                            </div>
                            @if ($row['is_settled'])
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                    <x-heroicon-m-check-circle class="w-3.5 h-3.5 text-emerald-500" />
                                    Settled
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-extrabold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 font-mono">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                    ₹{{ number_format($row['due_amount'], 2) }}
                                </span>
                            @endif
                        </div>

                        <div class="grid grid-cols-2 gap-2 py-2.5 text-xs">
                            <div class="bg-gray-50 dark:bg-white/[0.02] p-2 rounded-xl">
                                <div class="text-[10px] uppercase font-medium text-gray-400">Total Sent</div>
                                <div class="font-mono font-bold text-gray-800 dark:text-gray-200 mt-0.5">
                                    ₹{{ number_format($row['total_given'], 2) }}
                                </div>
                            </div>
                            <div class="bg-gray-50 dark:bg-white/[0.02] p-2 rounded-xl">
                                <div class="text-[10px] uppercase font-medium text-gray-400">Total Returned</div>
                                <div class="font-mono font-bold text-emerald-600 dark:text-emerald-400 mt-0.5">
                                    ₹{{ number_format($row['total_returned'], 2) }}
                                </div>
                            </div>
                        </div>

                        @if (! $row['is_settled'])
                            <div class="pt-1">
                                <button
                                    type="button"
                                    wire:click="openSettleModal({{ $row['receiver']->id }}, {{ $row['giver']->id }}, {{ $row['due_amount'] }})"
                                    class="w-full inline-flex items-center justify-center gap-2 py-2 px-3 rounded-xl text-xs font-bold bg-amber-500 text-white hover:bg-amber-600 active:scale-95 transition shadow-xs cursor-pointer"
                                >
                                    <x-heroicon-m-arrow-uturn-left class="w-4 h-4" />
                                    <span>{{ $row['receiver']->name }} માંથી પાછા નાખો (Settle)</span>
                                </button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Desktop Table View (hidden sm:block) --}}
            <div class="hidden sm:block overflow-x-auto rounded-2xl border border-gray-200/80 dark:border-white/10 bg-white dark:bg-gray-900 shadow-xs">
                <table class="w-full text-left text-sm divide-y divide-gray-200 dark:divide-white/10">
                    <thead class="bg-gray-50/80 dark:bg-white/[0.02] text-gray-600 dark:text-gray-400 font-semibold uppercase text-[11px] tracking-wider">
                        <tr>
                            <th class="py-3 px-4">Giver Account (જે ખાતામાંથી આપ્યા)</th>
                            <th class="py-3 px-4">Receiver Account (જે ખાતામાં મોકલ્યા)</th>
                            <th class="py-3 px-4 text-right">Total Sent (મોકલેલા)</th>
                            <th class="py-3 px-4 text-right">Total Returned (પાછા આવેલા)</th>
                            <th class="py-3 px-4 text-center">Status / બાકી હિસાબ</th>
                            <th class="py-3 px-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($settlements as $row)
                            <tr class="hover:bg-gray-50/60 dark:hover:bg-white/[0.02] transition {{ ! $row['is_settled'] ? 'bg-amber-50/20 dark:bg-amber-950/10' : '' }}">
                                {{-- Giver Account --}}
                                <td class="py-3 px-4">
                                    <div class="font-bold text-gray-900 dark:text-white">
                                        {{ $row['giver']->name }}
                                    </div>
                                    @if ($row['giver']->holder_name)
                                        <div class="text-xs text-gray-500">Held by: {{ $row['giver']->holder_name }}</div>
                                    @endif
                                </td>

                                {{-- Receiver Account --}}
                                <td class="py-3 px-4">
                                    <div class="font-bold text-gray-900 dark:text-white">
                                        {{ $row['receiver']->name }}
                                    </div>
                                    @if ($row['receiver']->holder_name)
                                        <div class="text-xs text-gray-500">Held by: {{ $row['receiver']->holder_name }}</div>
                                    @endif
                                </td>

                                {{-- Total Sent --}}
                                <td class="py-3 px-4 text-right font-mono font-semibold text-gray-700 dark:text-gray-300">
                                    ₹{{ number_format($row['total_given'], 2) }}
                                </td>

                                {{-- Total Returned --}}
                                <td class="py-3 px-4 text-right font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                                    ₹{{ number_format($row['total_returned'], 2) }}
                                </td>

                                {{-- Status / Pending Due --}}
                                <td class="py-3 px-4 text-center whitespace-nowrap">
                                    @if ($row['is_settled'])
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/40">
                                            <x-heroicon-m-check-circle class="w-4 h-4 text-emerald-500" />
                                            <span>Settled (હિસાબ ક્લિયર)</span>
                                        </span>
                                    @else
                                        <div class="inline-flex flex-col items-center">
                                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-extrabold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border border-amber-300 dark:border-amber-700/60 font-mono shadow-2xs">
                                                <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                                                ₹{{ number_format($row['due_amount'], 2) }} બાકી
                                            </span>
                                            <span class="text-[10px] text-amber-700 dark:text-amber-400 mt-0.5 font-medium">
                                                {{ $row['receiver']->name }} માંથી પાછા નાખવાના
                                            </span>
                                        </div>
                                    @endif
                                </td>

                                {{-- Action --}}
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    @if (! $row['is_settled'])
                                        <button
                                            type="button"
                                            wire:click="openSettleModal({{ $row['receiver']->id }}, {{ $row['giver']->id }}, {{ $row['due_amount'] }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-amber-500 text-white hover:bg-amber-600 active:scale-95 transition shadow-xs cursor-pointer"
                                            title="Record transfer back from {{ $row['receiver']->name }} to {{ $row['giver']->name }}"
                                        >
                                            <x-heroicon-m-arrow-uturn-left class="w-3.5 h-3.5" />
                                            <span>પાછા નાખો (Settle)</span>
                                        </button>
                                    @else
                                        <a
                                            href="{{ \App\Filament\Resources\AccountTransferResource::getUrl('index') }}"
                                            class="text-xs text-gray-500 hover:text-primary-600 underline"
                                        >
                                            View Logs
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="py-8 text-center rounded-2xl border border-dashed border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900">
                <div class="w-10 h-10 mx-auto rounded-full bg-emerald-100 dark:bg-emerald-950/60 flex items-center justify-center text-emerald-600 mb-2">
                    <x-heroicon-m-check class="w-6 h-6" />
                </div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                    કોઈ ખાતા વચ્ચે રકમ બાકી નથી (No Pending Inter-Account Dues)
                </h4>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    All inter-account transfers are fully settled.
                </p>
            </div>
        @endif

        {{-- Settle Modal --}}
        @if ($showSettleModal)
            @php
                $modalFrom = \App\Models\Account::find($settleFromAccountId);
                $modalTo = \App\Models\Account::find($settleToAccountId);
            @endphp
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 backdrop-blur-xs p-4">
                <div class="relative w-full max-w-md rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-2xl p-6">
                    <div class="flex items-center justify-between pb-3 border-b border-gray-100 dark:border-white/10">
                        <div class="flex items-center gap-2">
                            <div class="p-2 rounded-xl bg-amber-500/10 text-amber-600">
                                <x-heroicon-m-arrow-uturn-left class="w-5 h-5" />
                            </div>
                            <h3 class="text-base font-bold text-gray-950 dark:text-white">
                                રકમ પાછી જમા કરો (Settle Balance)
                            </h3>
                        </div>
                        <button
                            type="button"
                            wire:click="closeSettleModal"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-xl font-bold"
                        >
                            &times;
                        </button>
                    </div>

                    <div class="space-y-4 my-4">
                        {{-- From & To Overview --}}
                        <div class="p-3 rounded-xl bg-gray-50 dark:bg-white/[0.03] border border-gray-200/70 dark:border-white/5 space-y-2 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500">From (કયા ખાતામાંથી પાછા નીકળશે):</span>
                                <span class="font-bold text-gray-900 dark:text-white">{{ $modalFrom?->name }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500">To (કયા ખાતામાં જમા થશે):</span>
                                <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ $modalTo?->name }}</span>
                            </div>
                        </div>

                        {{-- Amount Input --}}
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                Transfer Amount (રકમ ₹)
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                wire:model="settleAmount"
                                class="w-full rounded-xl border border-gray-300 dark:border-white/20 bg-white dark:bg-gray-800 px-3.5 py-2 font-mono text-base font-bold text-gray-900 dark:text-white focus:ring-2 focus:ring-primary-500"
                            />
                        </div>

                        {{-- Notes Input --}}
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                Notes (નોંધ)
                            </label>
                            <input
                                type="text"
                                wire:model="settleNotes"
                                placeholder="e.g. Returned funds from IPO refund"
                                class="w-full rounded-xl border border-gray-300 dark:border-white/20 bg-white dark:bg-gray-800 px-3.5 py-2 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-gray-100 dark:border-white/10">
                        <button
                            type="button"
                            wire:click="closeSettleModal"
                            class="px-4 py-2 rounded-xl text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            wire:click="confirmSettle"
                            class="px-4 py-2 rounded-xl text-xs font-bold bg-primary-600 text-white hover:bg-primary-700 active:scale-95 transition shadow-xs"
                        >
                            Confirm Transfer (ટ્રાન્સફર નોંધો)
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
