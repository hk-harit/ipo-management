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
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Bank & Cash Accounts — Balances & IPO Blocked (ખાતાવાર બેલેન્સ અને બ્લોક રકમ)';

    public function table(Table $table): Table
    {
        return $table
            ->query(Account::query()->where('is_active', true))
            ->columns([
                TextColumn::make('name')
                    ->label('Account')
                    ->weight('bold')
                    ->description(fn (Account $record) => $record->holder_name ? "Held by: {$record->holder_name}" : null),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state) => $state === 'bank' ? 'info' : 'gray'),

                TextColumn::make('total_balance')
                    ->label('Total Balance (કુલ રકમ)')
                    ->state(fn (Account $record) => $record->total_balance)
                    ->money('INR')
                    ->weight('bold')
                    ->description('Total account capital'),

                TextColumn::make('blocked_in_ipos')
                    ->label('Blocked in IPOs (IPO માં બ્લોક)')
                    ->state(fn (Account $record) => $record->blocked_in_ipos)
                    ->money('INR')
                    ->weight('semibold')
                    ->color('primary')
                    ->description(function (Account $record) {
                        $cnt = $record->ipoApplications()->where('status', 'applied')->count();
                        return $cnt > 0 ? "{$cnt} app(s) pending" : 'None blocked';
                    }),

                TextColumn::make('current_balance')
                    ->label('Available Right Now (હાલમાં ઉપલબ્ધ)')
                    ->state(fn (Account $record) => $record->current_balance)
                    ->money('INR')
                    ->weight('bold')
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success')
                    ->description('Free balance to apply for IPOs'),
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
