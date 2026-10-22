<?php

namespace App\Filament\Resources\AccountResource\RelationManagers;

use App\Filament\Resources\AccountResource;
use App\Models\Account;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'subAccounts';

    protected static ?string $title = 'Sub Accounts (સબ એકાઉન્ટ્સ)';

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        if (! \App\Models\Account::hasSubAccountColumn()) {
            return false;
        }

        // Only main accounts can have sub-accounts
        return ! $ownerRecord->is_sub_account;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Sub-Account Name')
                ->placeholder('e.g. Mummy Demat UPI, Nirbhay Account')
                ->required()
                ->maxLength(255),
            TextInput::make('holder_name')
                ->label('Held By')
                ->placeholder('e.g. Mummy, Nirbhay, Papa')
                ->maxLength(255),
            Select::make('type')
                ->options([
                    'bank' => 'Bank',
                    'cash' => 'Cash',
                ])
                ->default('bank')
                ->required()
                ->native(false)
                ->live(),
            TextInput::make('bank_name')
                ->maxLength(255)
                ->visible(fn ($get) => $get('type') === 'bank'),
            TextInput::make('account_number')
                ->maxLength(255)
                ->visible(fn ($get) => $get('type') === 'bank'),
            TextInput::make('opening_balance')
                ->numeric()
                ->prefix('₹')
                ->required()
                ->default(0),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('holder_name')->label('Held By')->badge()->color('gray'),
                TextColumn::make('total_balance')
                    ->label('Total (કુલ)')
                    ->state(fn (Account $record) => $record->total_balance)
                    ->money('INR')
                    ->weight('bold'),
                TextColumn::make('blocked_in_ipos')
                    ->label('Blocked (બ્લોક)')
                    ->state(fn (Account $record) => $record->blocked_in_ipos)
                    ->money('INR')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('current_balance')
                    ->label('Available (ઉપલબ્ધ)')
                    ->state(fn (Account $record) => $record->current_balance)
                    ->money('INR')
                    ->weight('bold')
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('New Sub-Account (નવું સબ એકાઉન્ટ)')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['is_sub_account'] = true;
                        $data['parent_id'] = $this->getOwnerRecord()->id;
                        $data['user_id'] = $this->getOwnerRecord()->user_id;
                        return $data;
                    }),
            ])
            ->actions([
                Action::make('passbook')
                    ->label('Passbook')
                    ->icon('heroicon-o-book-open')
                    ->color('gray')
                    ->url(fn (Account $record) => AccountResource::getUrl('passbook', ['record' => $record])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
