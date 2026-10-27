<x-filament-panels::page>
    @php
        $money = fn (float $v) => \App\Services\SourceShare::inr($v);
        $counts = $this->counts();
        $groups = $this->groups();
        $stage = $this->stage;
        $tabs = [
            'request' => ['1. To request', 'Send the sell request (9:00 - 9:30)', '#d97706'],
            'waiting' => ['2. Request sent', 'Waiting to sell (about 10:00)', '#2563eb'],
            'sold' => ['3. Sold', 'Done', '#16a34a'],
        ];
    @endphp

    <div class="flex flex-wrap items-end gap-4">
        <div>
            <label for="sell-ipo" class="block text-xs font-medium text-gray-600 dark:text-gray-300">IPO</label>
            <select id="sell-ipo" wire:model.live="ipo"
                    class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                <option value="all">All IPOs</option>
                @foreach ($this->ipoOptions() as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </div>
        @if ($stage === 'waiting')
            <div>
                <label for="sell-price" class="block text-xs font-medium text-gray-600 dark:text-gray-300">Sale price per share (₹)</label>
                <input id="sell-price" type="number" step="0.01" min="0" inputmode="decimal" wire:model="price" placeholder="e.g. 1250.50"
                       class="w-40 rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
            </div>
            <div>
                <label for="sell-charges" class="block text-xs font-medium text-gray-600 dark:text-gray-300">Charges per application (₹)</label>
                <input id="sell-charges" type="number" step="0.01" min="0" inputmode="decimal" wire:model="charges" placeholder="0"
                       class="w-40 rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
            </div>
        @endif
    </div>

    {{-- The three stages, as big tabs with their counts --}}
    <div class="grid grid-cols-3 gap-2 sm:gap-3">
        @foreach ($tabs as $key => [$label, $hint, $colour])
            <button type="button" wire:click="showStage('{{ $key }}')" wire:key="tab-{{ $key }}"
                    style="{{ $stage === $key ? 'border-color:'.$colour.'; border-width:2px;' : '' }}"
                    class="rounded-xl border border-gray-200 p-3 text-left dark:border-white/10">
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="text-2xl font-bold" style="color:{{ $colour }}">{{ $counts[$key]['apps'] }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $counts[$key]['shares'] }} {{ $counts[$key]['shares'] === 1 ? 'share' : 'shares' }}
                    · {{ $counts[$key]['accounts'] }} {{ $counts[$key]['accounts'] === 1 ? 'account' : 'accounts' }}
                </div>
                <div class="mt-1 hidden text-xs text-gray-500 sm:block dark:text-gray-400">{{ $hint }}</div>
            </button>
        @endforeach
    </div>

    @if ($stage === 'request' && $counts['request']['apps'] > 0)
        <div>
            <button type="button" wire:click="requestAll"
                    wire:confirm="Mark all {{ $counts['request']['apps'] }} applications as 'request sent'?"
                    style="background:#d97706; color:#ffffff;" class="rounded-lg px-4 py-2 text-sm font-semibold shadow-sm">
                ✔ I sent all {{ $counts['request']['apps'] }} requests
            </button>
        </div>
    @endif

    @forelse ($groups as $group)
        <div class="rounded-lg border border-gray-200 dark:border-white/10" wire:key="group-{{ $stage }}-{{ $group['account_id'] }}">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 p-3 dark:border-white/10">
                <div>
                    <span class="text-base font-bold text-gray-950 dark:text-white">{{ $group['account'] }}</span>
                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-300">
                        {{ $group['count'] }} {{ $group['count'] === 1 ? 'application' : 'applications' }}
                        · <strong>{{ $group['shares'] }}</strong> {{ $group['shares'] === 1 ? 'share' : 'shares' }}
                    </span>
                </div>
                @if ($stage === 'request')
                    <button type="button" wire:click="requestAccount({{ $group['account_id'] }})"
                            wire:confirm="Mark all {{ $group['count'] }} requests of {{ $group['account'] }} as sent?"
                            style="background:#d97706; color:#ffffff;" class="rounded-lg px-3 py-1.5 text-sm font-semibold shadow-sm">
                        ✔ Requests sent for this account
                    </button>
                @elseif ($stage === 'waiting')
                    <button type="button" wire:click="soldAccount({{ $group['account_id'] }})"
                            wire:confirm="Mark all {{ $group['count'] }} applications of {{ $group['account'] }} as sold at the price typed at the top?"
                            style="background:#16a34a; color:#ffffff;" class="rounded-lg px-3 py-1.5 text-sm font-semibold shadow-sm">
                        ✔ All sold in this account
                    </button>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($group['rows'] as $app)
                            <tr wire:key="sell-row-{{ $app->id }}">
                                <td class="p-3">
                                    <div class="font-medium">{{ $app->applicant?->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $app->ipo?->name }}</div>
                                </td>
                                <td class="p-3 text-right">
                                    @if ($stage === 'sold')
                                        <div class="font-semibold">{{ (int) $app->shares_sold }} sh</div>
                                        <div class="text-xs text-gray-500">@ {{ $money((float) $app->sale_price_per_share) }}</div>
                                    @else
                                        <div class="font-semibold">{{ $app->shares_remaining }} sh</div>
                                        @if ($app->shares_sold)
                                            <div class="text-xs text-gray-500">{{ $app->shares_sold }} already sold</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="p-3 text-right text-xs text-gray-500">
                                    @if ($stage === 'waiting')
                                        request sent {{ $app->sell_requested_at?->format('d M, h:i A') }}
                                    @elseif ($stage === 'sold')
                                        sold {{ $app->sold_date?->format('d M') }}
                                    @endif
                                </td>
                                <td class="p-3 text-right">
                                    @if ($stage === 'request')
                                        <button type="button" wire:click="markRequested({{ $app->id }})"
                                                class="rounded-lg border border-warning-600 px-3 py-1 text-sm font-semibold text-warning-700 hover:bg-warning-50 dark:text-warning-400 dark:hover:bg-warning-500/10">
                                            ✔ Request sent
                                        </button>
                                    @elseif ($stage === 'waiting')
                                        <div class="flex items-center justify-end gap-2">
                                            <input type="number" step="0.01" min="0" inputmode="decimal" wire:model="prices.{{ $app->id }}"
                                                   placeholder="{{ $this->price !== '' ? $this->price : 'price' }}"
                                                   title="Different price for this one (blank = the price at the top)"
                                                   class="w-24 rounded-lg border-gray-300 bg-white text-right text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                                            <button type="button" wire:click="markSold({{ $app->id }})"
                                                    class="rounded-lg border border-success-600 px-3 py-1 text-sm font-semibold text-success-700 hover:bg-success-50 dark:text-success-400 dark:hover:bg-success-500/10">
                                                ✔ Sold
                                            </button>
                                            <button type="button" wire:click="undoRequested({{ $app->id }})" wire:confirm="Set this back to 'to request'?"
                                                    class="text-xs text-gray-500 underline hover:text-danger-600">undo</button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="rounded-lg border border-dashed border-gray-300 py-8 text-center text-gray-500 dark:border-white/10 dark:text-gray-400">
            @if ($stage === 'request')
                Nothing left to request. 🎉
            @elseif ($stage === 'waiting')
                No request is waiting to sell.
            @else
                Nothing sold yet.
            @endif
        </div>
    @endforelse
</x-filament-panels::page>
