<?php

namespace App\Filament\Widgets;

use App\Models\Account;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class AccountBalancesTable extends BaseWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Where Is My Money — Account Balances';

    public function table(Table $table): Table
    {
        return $table
            ->query(Account::query()->where('is_active', true))
            ->columns([
                TextColumn::make('name')->label('Account')->weight('semibold'),
                TextColumn::make('holder_name')->label('Held By')->placeholder('—'),
                TextColumn::make('type')->badge()
                    ->color(fn (string $state) => $state === 'bank' ? 'info' : 'gray'),
                TextColumn::make('opening_balance')->label('Opening')->money('INR')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('current_balance')
                    ->label('Current Balance')
                    ->state(fn (Account $record) => $record->current_balance)
                    ->money('INR')
                    ->weight('bold')
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success'),
            ])
            ->defaultSort('name')
            ->paginated(false);
    }
}
