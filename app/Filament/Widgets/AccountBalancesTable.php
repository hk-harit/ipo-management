<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AccountResource;
use App\Models\Account;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class AccountBalancesTable extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Current Account Balances — Bank & Cash (હાલમાં કયા ખાતામાં કેટલું બેલેન્સ છે)';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Account::query()
                    ->when(
                        Account::hasSubAccountColumn(),
                        fn ($q) => $q->where('is_sub_account', false)
                    )
                    ->where('is_active', true)
            )
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
                        if (Account::hasSubAccountColumn()) {
                            $subCount = $record->subAccounts()->count();
                            if ($subCount > 0) {
                                $subNames = $record->subAccounts()->pluck('name')->take(3)->join(', ');
                                $parts[] = "{$subCount} Sub-accounts ({$subNames})";
                            }
                        }
                        return implode(' · ', $parts);
                    }),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state) => $state === 'bank' ? 'info' : 'gray'),

                TextColumn::make('current_balance')
                    ->label('Available Right Now (હાલમાં ઉપલબ્ધ)')
                    ->state(fn (Account $record) => $record->current_balance)
                    ->money('INR')
                    ->weight('bold')
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success')
                    ->description('Free balance for new IPOs'),

                TextColumn::make('blocked_in_ipos')
                    ->label('Blocked in IPOs (IPO માં બ્લોક)')
                    ->state(fn (Account $record) => $record->blocked_in_ipos)
                    ->money('INR')
                    ->weight('semibold')
                    ->color('primary')
                    ->description(function (Account $record) {
                        $cnt = $record->ipoApplications()->where('status', 'applied')->count();
                        return $cnt > 0 ? "{$cnt} app(s) pending outcome" : 'None blocked';
                    }),

                TextColumn::make('total_balance')
                    ->label('Total Account Capital (ખાતાનું કુલ બેલેન્સ)')
                    ->state(fn (Account $record) => $record->total_balance)
                    ->money('INR')
                    ->weight('bold')
                    ->description('Available + Blocked (બેંક પાસબુક)'),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('view_type')
                    ->label('Filter Accounts')
                    ->options([
                        'main' => 'Main Bank & Cash Accounts (મુખ્ય ખાતાઓ)',
                        'all' => 'All Accounts including Sub-Accounts (તમામ ખાતાઓ)',
                    ])
                    ->default('main')
                    ->query(function ($query, array $data) {
                        if (($data['value'] ?? 'main') === 'all') {
                            return $query;
                        }
                        if (Account::hasSubAccountColumn()) {
                            $query->where('is_sub_account', false);
                        }
                        return $query;
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
