<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\ApplicationSale;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\FundTransaction;
use App\Models\IpoApplication;
use App\Models\ProfitPayout;
use App\Models\Source;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Read-only history of every change to a money record. */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Activity Log';

    protected static ?string $modelLabel = 'Activity';

    protected static ?string $pluralModelLabel = 'Activity Log';

    protected static ?int $navigationSort = 90;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('actor_name')->label('By')->placeholder('—'),
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('model_name')->label('Record')->description(fn (AuditLog $record) => $record->label),
                TextColumn::make('changes')
                    ->label('What changed')
                    ->state(fn (AuditLog $record) => implode("\n", $record->changeLines()))
                    ->wrap()
                    ->lineClamp(4)
                    ->tooltip(fn (AuditLog $record) => implode("\n", $record->changeLines())),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('event')->options([
                    'created' => 'Created',
                    'updated' => 'Updated',
                    'deleted' => 'Deleted',
                ]),
                SelectFilter::make('auditable_type')
                    ->label('Record')
                    ->options([
                        IpoApplication::class => 'IPO Applications',
                        ApplicationSale::class => 'Sales',
                        FundTransaction::class => 'Fund Transactions',
                        AccountTransfer::class => 'Account Transfers',
                        Expense::class => 'Expenses & Udhar',
                        ProfitPayout::class => 'Profit Payouts',
                        Account::class => 'Accounts',
                        Source::class => 'Sources',
                    ]),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
