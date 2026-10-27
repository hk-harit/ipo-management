<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AccountResource;
use App\Models\Account;
use App\Services\DashboardSummary;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class AccountBalancesTable extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Account Balances — Bank & Cash';

    public function table(Table $table): Table
    {
        // One set of aggregate queries for every row, instead of ~30 queries per account.
        $summary = new DashboardSummary;

        // A main account's row also counts its sub-accounts' blocked / demat money and
        // lists the cash sitting in them separately; a sub-account's row is just itself.
        $figures = function (Account $record) use ($summary): array {
            if ($record->is_sub_account) {
                return $summary->figuresFor($record->id) + ['in_sub_accounts' => 0.0];
            }

            $rollup = $summary->rollup($record->id);

            return [
                'balance' => $summary->figuresFor($record->id)['balance'],
                'blocked' => $rollup['blocked'],
                'refund_pending' => $rollup['refund_pending'],
                'invested' => $rollup['invested'],
                'in_sub_accounts' => $rollup['in_sub_accounts'],
            ];
        };

        return $table
            ->query(Account::query()->with('parent')->withCount('subAccounts')->where('is_active', true))
            ->columns([
                TextColumn::make('name')
                    ->label('Account')
                    ->weight('bold')
                    ->searchable()
                    ->description(function (Account $record) {
                        $parts = [];

                        if ($record->holder_name) {
                            $parts[] = "Held by: {$record->holder_name}";
                        }

                        if ($record->type === 'bank' && $record->account_number) {
                            $parts[] = 'A/C: ...'.substr($record->account_number, -4);
                        }

                        if ($record->is_sub_account && $record->parent) {
                            $parts[] = "Sub-account of {$record->parent->name}";
                        } elseif ($record->sub_accounts_count > 0) {
                            $parts[] = "{$record->sub_accounts_count} sub-account(s)";
                        }

                        return implode(' · ', $parts);
                    }),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state) => $state === 'bank' ? 'info' : 'gray')
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('available')
                    ->label('Available')
                    ->state(fn (Account $record) => $figures($record)['balance'])
                    ->money('INR')
                    ->weight('bold')
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success')
                    ->description('Free balance for new IPOs'),
                TextColumn::make('in_sub_accounts')
                    ->label('In Sub-accounts')
                    ->state(fn (Account $record) => $record->is_sub_account ? null : $figures($record)['in_sub_accounts'])
                    ->money('INR')
                    ->color('info')
                    ->placeholder('—')
                    ->description(fn (Account $record) => ! $record->is_sub_account && $figures($record)['in_sub_accounts'] != 0.0 ? 'to bring back to this account' : null),
                TextColumn::make('blocked')
                    ->label('Blocked in IPOs')
                    ->state(fn (Account $record) => $figures($record)['blocked'])
                    ->money('INR')
                    ->weight('semibold')
                    ->color('primary')
                    ->description(fn (Account $record) => ($figures($record)['refund_pending'] ?? 0) > 0 ? 'refund pending ₹'.number_format($figures($record)['refund_pending'], 0) : null),
                TextColumn::make('invested')
                    ->label('In Demat')
                    ->state(fn (Account $record) => $figures($record)['invested'])
                    ->money('INR')
                    ->color('warning'),
                TextColumn::make('total')
                    ->label('Total Capital')
                    ->state(function (Account $record) use ($figures) {
                        $f = $figures($record);

                        return $f['balance'] + $f['in_sub_accounts'] + $f['blocked'] + $f['invested'];
                    })
                    ->money('INR')
                    ->weight('bold')
                    ->description('Available + sub-accounts + Blocked + Demat'),
            ])
            ->filters([
                SelectFilter::make('view_type')
                    ->label('Filter Accounts')
                    ->options([
                        'main' => 'Main Bank & Cash Accounts',
                        'all' => 'All Accounts including Sub-Accounts',
                    ])
                    ->default('main')
                    ->query(function ($query, array $data) {
                        if (($data['value'] ?? 'main') === 'all' || ! Account::hasSubAccountColumn()) {
                            return $query;
                        }

                        return $query->where('is_sub_account', false);
                    }),
            ])
            ->actions([
                Action::make('passbook')
                    ->label('Passbook / Ledger')
                    ->icon('heroicon-o-book-open')
                    ->color('gray')
                    ->url(fn (Account $record) => AccountResource::getUrl('passbook', ['record' => $record])),
            ])
            ->defaultSort('name')
            ->paginated(false);
    }
}
