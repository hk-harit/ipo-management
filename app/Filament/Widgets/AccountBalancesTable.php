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

    protected static ?string $heading = 'Account Balances — Bank & Cash (ખાતાવાર બેલેન્સ)';

    public function table(Table $table): Table
    {
        // One set of aggregate queries for every row, instead of ~30 queries per account.
        $summary = new DashboardSummary;

        return $table
            ->query(Account::query()->with('parent')->withCount('subAccounts')->where('is_active', true))
            ->columns([
                TextColumn::make('name')
                    ->label('Account (ખાતું)')
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
                    ->label('Available (ઉપલબ્ધ)')
                    ->state(fn (Account $record) => $summary->figuresFor($record->id)['balance'])
                    ->money('INR')
                    ->weight('bold')
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success')
                    ->description('Free balance for new IPOs'),
                TextColumn::make('blocked')
                    ->label('Blocked in IPOs (IPO માં બ્લોક)')
                    ->state(fn (Account $record) => $summary->figuresFor($record->id)['blocked'])
                    ->money('INR')
                    ->weight('semibold')
                    ->color('primary'),
                TextColumn::make('invested')
                    ->label('In Demat (ડીમેટમાં)')
                    ->state(fn (Account $record) => $summary->figuresFor($record->id)['invested'])
                    ->money('INR')
                    ->color('warning'),
                TextColumn::make('total')
                    ->label('Total Capital (કુલ)')
                    ->state(function (Account $record) use ($summary) {
                        $figures = $summary->figuresFor($record->id);

                        return $figures['balance'] + $figures['blocked'] + $figures['invested'];
                    })
                    ->money('INR')
                    ->weight('bold')
                    ->description('Available + Blocked + Demat'),
            ])
            ->filters([
                SelectFilter::make('view_type')
                    ->label('Filter Accounts')
                    ->options([
                        'main' => 'Main Bank & Cash Accounts (મુખ્ય ખાતાઓ)',
                        'all' => 'All Accounts including Sub-Accounts (તમામ ખાતાઓ)',
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
