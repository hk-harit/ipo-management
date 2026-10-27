<x-filament-panels::page>
    @php
        $money = fn (float $v) => \App\Services\SourceShare::inr($v);
        $totals = $this->totals();
        $pending = $this->pending();
        $received = $this->received();
    @endphp

    <div class="flex flex-wrap items-end gap-4">
        <div>
            <label for="refund-ipo" class="block text-xs font-medium text-gray-600 dark:text-gray-300">IPO</label>
            <select id="refund-ipo" wire:model.live="ipo"
                    class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                <option value="all">Badha IPO</option>
                @foreach ($this->ipoOptions() as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="refund-date" class="block text-xs font-medium text-gray-600 dark:text-gray-300">Refund aavyani tarikh</label>
            <input id="refund-date" type="date" wire:model="date"
                   class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border p-4 {{ $totals['pending_count'] ? 'border-danger-500/50' : 'border-gray-200 dark:border-white/10' }}">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Refund aavvanu baki</div>
            <div class="text-2xl font-bold {{ $totals['pending_count'] ? 'text-danger-600 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">{{ $money($totals['pending_amount']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $totals['pending_count'] }} application · {{ $pending->count() }} account ma</div>
        </div>
        <div class="rounded-xl border border-success-500/40 p-4">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Refund aavi gayu (30 divas)</div>
            <div class="text-2xl font-bold text-success-600 dark:text-success-400">{{ $money($totals['received_amount']) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $totals['received_count'] }} application</div>
        </div>
    </div>

    {{-- Still to come, account by account --}}
    <x-filament::section heading="Refund aavvanu baki — kaya account ma (કયા ખાતામાં આવશે)">
        @forelse ($pending as $group)
            <div class="mb-4 rounded-lg border border-gray-200 dark:border-white/10" wire:key="pending-{{ $group['account_id'] }}">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 p-3 dark:border-white/10">
                    <div>
                        <span class="text-base font-bold text-gray-950 dark:text-white">{{ $group['account'] }}</span>
                        <span class="ml-2 text-sm text-gray-600 dark:text-gray-300">{{ $group['count'] }} refund · <strong class="text-danger-600 dark:text-danger-400">{{ $money($group['total']) }}</strong></span>
                    </div>
                    <button type="button"
                            wire:click="markAccountReceived({{ $group['account_id'] }})"
                            wire:confirm="{{ $group['account'] }} na badha {{ $group['count'] }} refund aavi gaya em mark karu? (jema amount badlyo hoy te mujab)"
                            style="background:#16a34a; color:#ffffff;"
                            class="rounded-lg px-3 py-1.5 text-sm font-semibold shadow-sm">
                        ✔ Aa account na badha aavi gaya
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($group['rows'] as $app)
                                <tr wire:key="pending-row-{{ $app->id }}">
                                    <td class="p-3">
                                        <div class="font-medium">{{ $app->applicant?->name }}</div>
                                        <div class="text-xs text-gray-500">{{ $app->applicant?->pan_number }}</div>
                                    </td>
                                    <td class="p-3">
                                        {{ $app->ipo?->name }}
                                        @if ($app->ipo?->allotment_date)
                                            <div class="text-xs text-gray-500">allotment {{ $app->ipo->allotment_date->format('d M') }} · {{ $app->ipo->allotment_date->diffInDays(today()) }} divas thaya</div>
                                        @endif
                                    </td>
                                    <td class="p-3 text-right font-semibold">{{ $money((float) $app->amount) }}</td>
                                    <td class="p-3 text-right">
                                        <input type="number" step="0.01" min="0" max="{{ $app->amount }}" inputmode="decimal"
                                               wire:model="amounts.{{ $app->id }}" placeholder="{{ number_format((float) $app->amount, 2, '.', '') }}"
                                               title="Aavelo amount (khali = puro)"
                                               class="w-28 rounded-lg border-gray-300 bg-white text-right text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                                    </td>
                                    <td class="p-3 text-right">
                                        <button type="button" wire:click="markReceived({{ $app->id }})"
                                                class="rounded-lg border border-success-600 px-3 py-1 text-sm font-semibold text-success-700 hover:bg-success-50 dark:text-success-400 dark:hover:bg-success-500/10">
                                            ✔ Aavyu
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="py-6 text-center text-gray-500 dark:text-gray-400">Badha refund aavi gaya chhe. 🎉 Koi refund baki nathi.</div>
        @endforelse
    </x-filament::section>

    {{-- Arrived --}}
    <x-filament::section heading="Refund aavi gaya (છેલ્લા 30 દિવસ)" collapsible collapsed>
        @forelse ($received as $group)
            <div class="mb-3">
                <div class="mb-1 text-sm font-bold text-gray-950 dark:text-white">{{ $group['account'] }}
                    <span class="font-normal text-gray-600 dark:text-gray-300">· {{ $group['count'] }} · {{ $money($group['total']) }}</span></div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($group['rows'] as $app)
                            <tr wire:key="received-row-{{ $app->id }}">
                                <td class="py-1.5 pr-3">{{ $app->applicant?->name }} <span class="text-xs text-gray-500">· {{ $app->ipo?->name }}</span></td>
                                <td class="py-1.5 pr-3 text-right text-xs text-gray-500">{{ $app->return_date?->format('d M') }}</td>
                                <td class="py-1.5 pr-3 text-right font-semibold">
                                    {{ $money((float) $app->return_amount) }}
                                    @if ((float) $app->return_amount < (float) $app->amount)
                                        <span class="text-xs font-normal text-danger-600 dark:text-danger-400">(−{{ $money((float) $app->amount - (float) $app->return_amount) }})</span>
                                    @endif
                                </td>
                                <td class="py-1.5 text-right">
                                    <button type="button" wire:click="undoReceived({{ $app->id }})" wire:confirm="Aa refund pachu 'baki' kari dau?"
                                            class="text-xs text-gray-500 underline hover:text-danger-600">pachu baki</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <div class="text-sm text-gray-500 dark:text-gray-400">Chhella 30 divas ma koi refund mark thayu nathi.</div>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
