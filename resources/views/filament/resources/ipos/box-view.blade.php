<div class="w-full">
    {{-- Top View Switcher & Counter Bar --}}
    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 bg-gray-50/70 dark:bg-white/[0.03] border-b border-gray-200/70 dark:border-white/10">
        <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
            <span class="inline-flex items-center gap-1.5 font-semibold text-gray-800 dark:text-gray-200">
                <x-heroicon-m-squares-2x2 class="w-4 h-4 text-primary-600 dark:text-primary-400" />
                Box View (Card Grid)
            </span>
            <span>·</span>
            <span>{{ method_exists($records, 'total') ? $records->total() : count($records) }} IPO(s)</span>
        </div>

        <div class="inline-flex items-center gap-1 bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 rounded-lg p-0.5 shadow-xs">
            <button
                type="button"
                wire:click="setViewMode('box')"
                class="px-3 py-1 rounded-md text-xs font-semibold flex items-center gap-1.5 transition {{ ($livewire->viewMode ?? 'box') === 'box' ? 'bg-primary-600 text-white shadow-xs' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white' }}"
            >
                <x-heroicon-m-squares-2x2 class="w-3.5 h-3.5" />
                <span>Box View</span>
            </button>
            <button
                type="button"
                wire:click="setViewMode('table')"
                class="px-3 py-1 rounded-md text-xs font-semibold flex items-center gap-1.5 transition {{ ($livewire->viewMode ?? 'box') === 'table' ? 'bg-primary-600 text-white shadow-xs' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white' }}"
            >
                <x-heroicon-m-table-cells class="w-3.5 h-3.5" />
                <span>Table View</span>
            </button>
        </div>
    </div>

    {{-- Cards Grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 p-4 sm:p-5">
        @forelse ($records as $record)
            @php
                $status = $record->status;
                $statusColors = match ($status) {
                    'open' => ['bg' => 'bg-emerald-50 dark:bg-emerald-950/40', 'text' => 'text-emerald-700 dark:text-emerald-300', 'border' => 'border-emerald-200 dark:border-emerald-800/40', 'dot' => 'bg-emerald-500'],
                    'upcoming' => ['bg' => 'bg-sky-50 dark:bg-sky-950/40', 'text' => 'text-sky-700 dark:text-sky-300', 'border' => 'border-sky-200 dark:border-sky-800/40', 'dot' => 'bg-sky-500'],
                    'closed' => ['bg' => 'bg-amber-50 dark:bg-amber-950/40', 'text' => 'text-amber-700 dark:text-amber-300', 'border' => 'border-amber-200 dark:border-amber-800/40', 'dot' => 'bg-amber-500'],
                    'allotted' => ['bg' => 'bg-purple-50 dark:bg-purple-950/40', 'text' => 'text-purple-700 dark:text-purple-300', 'border' => 'border-purple-200 dark:border-purple-800/40', 'dot' => 'bg-purple-500'],
                    'listed' => ['bg' => 'bg-indigo-50 dark:bg-indigo-950/40', 'text' => 'text-indigo-700 dark:text-indigo-300', 'border' => 'border-indigo-200 dark:border-indigo-800/40', 'dot' => 'bg-indigo-500'],
                    default => ['bg' => 'bg-gray-100 dark:bg-white/10', 'text' => 'text-gray-700 dark:text-gray-300', 'border' => 'border-gray-200 dark:border-white/10', 'dot' => 'bg-gray-400'],
                };

                $lastUpdated = $record->subscription_updated_at ?? $record->gmp_updated_at ?? $record->synced_at ?? $record->updated_at;
                $editUrl = \App\Filament\Resources\IpoResource::getUrl('edit', ['record' => $record]);
                $retailSub = $record->subscription_retail !== null ? (float) $record->subscription_retail : null;
                $gmp = $record->gmp !== null ? (float) $record->gmp : null;
                $appsCount = $record->applications_count ?? $record->applications()->count();
            @endphp

            <div class="group relative flex flex-col justify-between rounded-2xl border border-gray-200/90 dark:border-white/10 bg-white dark:bg-gray-900 p-4 sm:p-5 shadow-xs hover:shadow-md hover:border-primary-400/60 dark:hover:border-primary-500/40 transition duration-200">
                {{-- Header: Name, Symbol, Board, Status, Last Updated --}}
                <div>
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <a href="{{ $editUrl }}" class="block font-bold text-base sm:text-lg text-gray-950 dark:text-white group-hover:text-primary-600 dark:group-hover:text-primary-400 transition truncate" title="{{ $record->name }}">
                                {{ $record->name }}
                            </a>
                            <div class="flex flex-wrap items-center gap-1.5 mt-1 text-xs text-gray-500 dark:text-gray-400">
                                @if ($record->symbol)
                                    <span class="font-mono font-medium text-gray-700 dark:text-gray-300">{{ $record->symbol }}</span>
                                    <span>·</span>
                                @endif
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $record->isMainboard() ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200/60 dark:border-indigo-800/40' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200/60 dark:border-amber-800/40' }}">
                                    {{ $record->issue_type === 'SME' ? 'SME' : 'Mainboard' }}
                                </span>
                            </div>
                        </div>

                        {{-- Status Pill --}}
                        <div class="flex flex-col items-end gap-1 shrink-0">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold border {{ $statusColors['bg'] }} {{ $statusColors['text'] }} {{ $statusColors['border'] }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $statusColors['dot'] }} {{ $status === 'open' ? 'animate-pulse' : '' }}"></span>
                                {{ ucfirst($status) }}
                            </span>
                            @if ($lastUpdated)
                                <span class="text-[10px] text-gray-400 dark:text-gray-500 flex items-center gap-1" title="Last updated: {{ $lastUpdated->format('d M Y, h:i A') }}">
                                    <x-heroicon-m-clock class="w-3 h-3 text-gray-400" />
                                    {{ $lastUpdated->diffForHumans() }}
                                </span>
                            @endif
                        </div>
                    </div>

                    {{-- 4-Block Metrics Grid --}}
                    <div class="grid grid-cols-2 gap-2.5 mt-4">
                        {{-- 1. Issue Price & 1 Lot --}}
                        <div class="p-2.5 rounded-xl bg-gray-50/80 dark:bg-white/[0.04] border border-gray-100 dark:border-white/5">
                            <div class="text-[10px] font-bold tracking-wider uppercase text-gray-500 dark:text-gray-400">
                                Issue Price
                            </div>
                            <div class="mt-0.5 font-bold text-sm sm:text-base text-gray-900 dark:text-white truncate">
                                {{ $record->price_band ?: ($record->price_per_share ? '₹'.number_format($record->price_per_share, 2) : '—') }}
                            </div>
                            <div class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400 flex items-center justify-between">
                                <span>1 Lot:</span>
                                <span class="font-semibold text-gray-700 dark:text-gray-300">
                                    {{ $record->lot_amount ? '₹'.number_format($record->lot_amount) : '—' }}
                                    @if ($record->lot_size)
                                        <span class="font-normal text-gray-400">({{ $record->lot_size }})</span>
                                    @endif
                                </span>
                            </div>
                        </div>

                        {{-- 2. GMP (Grey Market) --}}
                        <div class="p-2.5 rounded-xl bg-gray-50/80 dark:bg-white/[0.04] border border-gray-100 dark:border-white/5">
                            <div class="text-[10px] font-bold tracking-wider uppercase text-gray-500 dark:text-gray-400 flex items-center justify-between">
                                <span>GMP</span>
                                @if ($record->gmp_percent !== null)
                                    <span class="text-[10px] font-semibold {{ $gmp > 0 ? 'text-emerald-600 dark:text-emerald-400' : ($gmp < 0 ? 'text-rose-600' : 'text-gray-400') }}">
                                        {{ ($gmp > 0 ? '+' : '').number_format($record->gmp_percent, 1) }}%
                                    </span>
                                @endif
                            </div>
                            <div class="mt-0.5 font-bold text-sm sm:text-base {{ $gmp > 0 ? 'text-emerald-600 dark:text-emerald-400' : ($gmp < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-500 dark:text-gray-400') }}">
                                @if ($gmp !== null)
                                    {{ ($gmp > 0 ? '+₹' : '₹').number_format($gmp, 2) }}
                                @else
                                    —
                                @endif
                            </div>
                            <div class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400 flex items-center justify-between">
                                <span>Est. Gain:</span>
                                <span class="font-semibold {{ $gmp > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500' }}">
                                    {{ $record->estimated_gain_per_lot !== null ? '₹'.number_format($record->estimated_gain_per_lot) : '—' }}
                                </span>
                            </div>
                        </div>

                        {{-- 3. Retail Subscription --}}
                        <div class="p-2.5 rounded-xl bg-gray-50/80 dark:bg-white/[0.04] border border-gray-100 dark:border-white/5">
                            <div class="text-[10px] font-bold tracking-wider uppercase text-gray-500 dark:text-gray-400">
                                Retail Sub.
                            </div>
                            <div class="mt-0.5 font-bold text-sm sm:text-base flex items-baseline gap-1 {{ $retailSub >= 2 ? 'text-emerald-600 dark:text-emerald-400' : ($retailSub >= 1 ? 'text-amber-600 dark:text-amber-400' : ($retailSub !== null ? 'text-rose-600 dark:text-rose-400' : 'text-gray-500 dark:text-gray-400')) }}">
                                <span>{{ $retailSub !== null ? number_format($retailSub, 2).'x' : '—' }}</span>
                            </div>
                            <div class="mt-0.5 text-[11px] font-medium text-gray-500 dark:text-gray-400 truncate">
                                @if ($retailSub !== null)
                                    <span class="{{ $retailSub >= 1 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                                        {{ $retailSub >= 1 ? 'Oversubscribed' : 'Bids In Progress' }}
                                    </span>
                                @else
                                    <span>Not updated yet</span>
                                @endif
                            </div>
                        </div>

                        {{-- 4. Application-wise Subscription --}}
                        <div class="p-2.5 rounded-xl bg-gray-50/80 dark:bg-white/[0.04] border border-gray-100 dark:border-white/5">
                            <div class="text-[10px] font-bold tracking-wider uppercase text-gray-500 dark:text-gray-400 flex items-center justify-between">
                                <span>Applications</span>
                                <x-heroicon-m-user-group class="w-3 h-3 text-gray-400" />
                            </div>
                            <div class="mt-0.5 font-bold text-sm sm:text-base text-gray-900 dark:text-white truncate">
                                {{ $record->subscription_applications ? number_format($record->subscription_applications) : '—' }}
                            </div>
                            <div class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400 flex items-center justify-between">
                                <span>Total Sub:</span>
                                <span class="font-semibold text-gray-700 dark:text-gray-300">
                                    {{ $record->subscription_total !== null ? number_format((float) $record->subscription_total, 2).'x' : '—' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    {{-- Timeline Strip --}}
                    <div class="mt-3 px-3 py-2 rounded-xl bg-gray-50/50 dark:bg-white/[0.02] border border-gray-100 dark:border-white/5 flex items-center justify-between text-xs">
                        <div class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400">
                            <x-heroicon-m-calendar class="w-3.5 h-3.5 text-gray-400" />
                            <span>{{ $record->open_date ? $record->open_date->format('d M') : '—' }}</span>
                            <span class="text-gray-300 dark:text-gray-600">→</span>
                            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $record->close_date ? $record->close_date->format('d M Y') : '—' }}</span>
                        </div>

                        @if ($status === 'open' && $record->close_date)
                            @php
                                $daysLeft = today()->diffInDays($record->close_date, false);
                            @endphp
                            @if ($daysLeft === 0)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300">
                                    Closes Today!
                                </span>
                            @elseif ($daysLeft === 1)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
                                    Closes Tomorrow
                                </span>
                            @elseif ($daysLeft > 1)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                    {{ $daysLeft }} days left
                                </span>
                            @endif
                        @elseif ($status === 'upcoming' && $record->open_date)
                            @php
                                $daysToOpen = today()->diffInDays($record->open_date, false);
                            @endphp
                            @if ($daysToOpen === 0)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                    Opens Today!
                                </span>
                            @elseif ($daysToOpen === 1)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300">
                                    Opens Tomorrow
                                </span>
                            @endif
                        @endif
                    </div>
                </div>

                {{-- Card Footer & Actions --}}
                <div class="mt-4 pt-3 border-t border-gray-100 dark:border-white/5 flex items-center justify-between gap-2">
                    {{-- My Applications Pill --}}
                    <div>
                        @if ($appsCount > 0)
                            <a
                                href="{{ \App\Filament\Resources\IpoApplicationResource::getUrl('index') }}?tableFilters[ipo_id][value]={{ $record->id }}"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-primary-50 text-primary-700 dark:bg-primary-950/60 dark:text-primary-300 hover:bg-primary-100 dark:hover:bg-primary-900 transition"
                                title="View your applications for this IPO"
                            >
                                <x-heroicon-m-check-circle class="w-3.5 h-3.5 text-primary-600 dark:text-primary-400" />
                                <span>{{ $appsCount }} Applied</span>
                                @if ($record->total_applied > 0)
                                    <span class="text-primary-500 font-normal">(₹{{ number_format($record->total_applied) }})</span>
                                @endif
                            </a>
                        @else
                            <span class="text-xs text-gray-400 dark:text-gray-500 italic">
                                0 applied
                            </span>
                        @endif
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center gap-1.5">
                        @if ($appsCount > 0)
                            <button
                                type="button"
                                wire:click="mountTableAction('checkAllotment', '{{ $record->getKey() }}')"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-semibold bg-emerald-600 text-white hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500 shadow-xs transition"
                                title="Check Allotment Status"
                            >
                                <x-heroicon-m-magnifying-glass-circle class="w-3.5 h-3.5" />
                                <span>Allotment</span>
                            </button>
                        @endif

                        <a
                            href="{{ \App\Filament\Resources\IpoApplicationResource::getUrl('create') }}?ipo_id={{ $record->id }}"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-semibold bg-primary-50 text-primary-700 hover:bg-primary-100 dark:bg-primary-950/60 dark:text-primary-300 dark:hover:bg-primary-900 transition"
                            title="Apply in this IPO"
                        >
                            <x-heroicon-m-plus class="w-3.5 h-3.5" />
                            <span>Apply</span>
                        </a>

                        <a
                            href="{{ $editUrl }}"
                            class="p-1 rounded-lg text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-white/5 transition"
                            title="Edit IPO details"
                        >
                            <x-heroicon-m-pencil-square class="w-4 h-4" />
                        </a>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full py-12 text-center">
                <div class="w-12 h-12 mx-auto rounded-full bg-gray-100 dark:bg-white/5 flex items-center justify-center text-gray-400">
                    <x-heroicon-o-folder-open class="w-6 h-6" />
                </div>
                <h3 class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">No IPOs found</h3>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">There are no IPOs matching the current filter or tab.</p>
            </div>
        @endforelse
    </div>
</div>
