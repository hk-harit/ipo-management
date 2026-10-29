<x-filament-panels::page>
    @php
        $money = fn (float $v) => \App\Services\SourceShare::inr($v);
        $totals = $this->totals();
        $collect = $this->collectList();
        $sources = $this->sourceList();
        $banks = $this->bankOptions();
        $dest = $this->destinationAccount();
        $payAccount = $this->payFrom !== '' ? $banks->firstWhere('id', (int) $this->payFrom) : null;
    @endphp

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">To collect now</div>
            <div class="text-2xl font-bold text-success-600 dark:text-success-400">{{ $money($totals['to_collect']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">in {{ $totals['accounts'] }} {{ $totals['accounts'] === 1 ? 'account' : 'accounts' }}</div>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Still blocked in IPOs</div>
            <div class="text-2xl font-bold text-warning-600 dark:text-warning-400">{{ $money($totals['still_blocked']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">refund or result still to come</div>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">To pay the sources</div>
            <div class="text-2xl font-bold text-danger-600 dark:text-danger-400">{{ $money($totals['to_pay_sources']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">money back + profit share</div>
        </div>
    </div>

    {{-- 1. Collect --}}
    <x-filament::section heading="1. Take the money back">
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">
            Click a name to see where the money goes back. It goes to the bank you sent it from.
        </p>
        <div class="mb-4">
            <label for="md-dest" class="block text-xs font-medium text-gray-600 dark:text-gray-300">Extra money (profit) goes to</label>
            <select id="md-dest" wire:model.live="destination"
                    class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                @foreach ($banks as $bank)
                    <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                @endforeach
            </select>
        </div>

        @forelse ($collect as $row)
            <div class="mb-3 rounded-lg border border-gray-200 dark:border-white/10" wire:key="collect-{{ $row['id'] }}" x-data="{ open: false }">
                <button type="button" x-on:click="open = ! open" class="flex w-full flex-wrap items-center justify-between gap-2 p-3 text-left">
                    <span>
                        <span class="text-base font-bold text-gray-950 dark:text-white">{{ $row['name'] }}</span>
                        @if ($row['holder'])
                            <span class="text-sm text-gray-500 dark:text-gray-400">· ask {{ $row['holder'] }}</span>
                        @endif
                        <span class="block text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($row['lines'] as $line)
                                {{ $money($line['amount']) }} → <strong>{{ $line['bank'] }}</strong>@if (! $loop->last), @endif
                            @endforeach
                            @if (empty($row['lines']))
                                nothing to take back now
                            @endif
                        </span>
                        @if ($row['blocked'] > 0)
                            <span class="block text-xs text-warning-600 dark:text-warning-400">{{ $money($row['blocked']) }} still blocked in IPOs (refund pending {{ $money($row['refund_pending']) }})</span>
                        @endif
                    </span>
                    <span class="text-xl font-bold text-success-600 dark:text-success-400">{{ $money($row['free']) }}</span>
                </button>

                <div x-show="open" x-cloak class="border-t border-gray-200 p-3 dark:border-white/10">
                    @foreach ($row['lines'] as $line)
                        @php
                            $bank = $banks->firstWhere('id', $line['bank_id']);
                            $where = trim($line['bank'].($bank?->bank_name ? ' ('.$bank->bank_name.')' : '').($bank?->account_number ? ', A/c '.$bank->account_number : ''));
                            $message = 'Please put '.$money($line['amount']).' in '.$where.'. Thank you.';
                        @endphp
                        <div class="mb-3 last:mb-0" wire:key="line-{{ $row['id'] }}-{{ $line['bank_id'] }}" x-data="{ copied: false, text: @js($message) }">
                            <div class="rounded-md bg-gray-50 p-2 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-200">{{ $message }}</div>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" x-on:click="navigator.clipboard.writeText(text).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-semibold dark:border-white/20">
                                    <span x-show="!copied">Copy message</span><span x-show="copied" x-cloak>Copied ✔</span>
                                </button>
                                <a href="https://wa.me/?text={{ rawurlencode($message) }}" target="_blank" rel="noopener"
                                   style="background:#16a34a; color:#ffffff;" class="rounded-lg px-3 py-1.5 text-sm font-semibold shadow-sm">WhatsApp</a>
                                <span class="ml-auto flex items-center gap-2">
                                    <input type="number" step="0.01" min="0" inputmode="decimal" wire:model="amounts.{{ $row['id'].'-'.$line['bank_id'] }}"
                                           placeholder="{{ number_format($line['amount'], 2, '.', '') }}" title="Amount that was put in (blank = all)"
                                           class="w-28 rounded-lg border-gray-300 bg-white text-right text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                                    <button type="button" wire:click="collectedLine({{ $row['id'] }}, {{ $line['bank_id'] }})"
                                            wire:confirm="Money received in {{ $line['bank'] }} from {{ $row['name'] }}?"
                                            class="rounded-lg border border-success-600 px-3 py-1.5 text-sm font-semibold text-success-700 hover:bg-success-50 dark:text-success-400 dark:hover:bg-success-500/10">
                                        ✔ Money received
                                    </button>
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="py-6 text-center text-gray-500 dark:text-gray-400">No account is holding free money. 🎉</div>
        @endforelse
    </x-filament::section>
    {{-- 2. Pay the sources --}}
    <x-filament::section heading="2. Pay the sources back">
        <div class="mb-4">
            <label for="md-pay" class="block text-xs font-medium text-gray-600 dark:text-gray-300">Pay from this account</label>
            <select id="md-pay" wire:model.live="payFrom"
                    class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                @foreach ($banks as $bank)
                    <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                @endforeach
            </select>
        </div>

        @forelse ($sources as $row)
            @php
                $message = "Hello ".$row['name'].",\n"
                    ."Your money back: ".$money($row['principal'])." (given ".$money($row['given']).", already returned ".$money($row['returned']).")\n"
                    ."Your profit share: ".$money($row['profit'])."\n"
                    ."Total to pay you: ".$money($row['total']);
                $number = \App\Services\SourceShare::whatsappNumber($row['mobile']);
            @endphp
            <div class="mb-3 rounded-lg border border-gray-200 p-3 dark:border-white/10" wire:key="source-{{ $row['id'] }}"
                 x-data="{ copied: false, text: @js($message) }">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-base font-bold text-gray-950 dark:text-white">{{ $row['name'] }}</span>
                    <span class="text-xl font-bold text-danger-600 dark:text-danger-400">{{ $money($row['total']) }}</span>
                </div>
                <table class="mt-2 w-full text-sm">
                    <tbody>
                        <tr><td class="py-0.5 text-gray-600 dark:text-gray-300">Money given</td><td class="py-0.5 text-right">{{ $money($row['given']) }}</td></tr>
                        <tr><td class="py-0.5 text-gray-600 dark:text-gray-300">Already returned</td><td class="py-0.5 text-right">− {{ $money($row['returned']) }}</td></tr>
                        <tr class="font-semibold"><td class="py-0.5">Money back to pay</td><td class="py-0.5 text-right">{{ $money($row['principal']) }}</td></tr>
                        <tr class="font-semibold"><td class="py-0.5">Profit share to pay</td><td class="py-0.5 text-right">{{ $money($row['profit']) }}</td></tr>
                    </tbody>
                </table>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button type="button" x-on:click="navigator.clipboard.writeText(text).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-semibold dark:border-white/20">
                        <span x-show="!copied">Copy message</span><span x-show="copied" x-cloak>Copied ✔</span>
                    </button>
                    <a href="https://wa.me/{{ $number }}?text={{ rawurlencode($message) }}" target="_blank" rel="noopener"
                       style="background:#16a34a; color:#ffffff;" class="rounded-lg px-3 py-1.5 text-sm font-semibold shadow-sm">WhatsApp</a>
                    <span class="ml-auto flex flex-wrap items-center gap-2">
                        <input type="number" step="0.01" min="0" inputmode="decimal" wire:model="principal.{{ $row['id'] }}"
                               placeholder="money {{ number_format($row['principal'], 0, '.', '') }}" title="Money back to pay now (blank = all)"
                               class="w-28 rounded-lg border-gray-300 bg-white text-right text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                        <input type="number" step="0.01" min="0" inputmode="decimal" wire:model="profit.{{ $row['id'] }}"
                               placeholder="profit {{ number_format($row['profit'], 0, '.', '') }}" title="Profit share to pay now (blank = all)"
                               class="w-28 rounded-lg border-gray-300 bg-white text-right text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                        <button type="button" wire:click="paySource({{ $row['id'] }})"
                                wire:confirm="Record payment to {{ $row['name'] }} from {{ $payAccount?->name }}?"
                                style="background:#16a34a; color:#ffffff;" class="rounded-lg px-3 py-1.5 text-sm font-semibold shadow-sm">
                            ✔ Paid
                        </button>
                    </span>
                </div>
            </div>
        @empty
            <div class="py-6 text-center text-gray-500 dark:text-gray-400">Every source is paid. 🎉</div>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
