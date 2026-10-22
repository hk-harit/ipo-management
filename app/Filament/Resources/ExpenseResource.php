<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Expenses';

    protected static ?string $navigationLabel = 'Expenses & Udhar';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->schema([
                Select::make('type')
                    ->label('Type (પ્રકાર)')
                    ->options([
                        'expense' => 'Regular Expense (સામાન્ય ખર્ચ)',
                        'udhar_given' => 'Udhar Given / Lent (કોઈને ઉધાર આપ્યા - લેવાના છે)',
                        'udhar_received' => 'Udhar Received (ઉધાર પાછા મળ્યા / જમા)',
                    ])
                    ->default('expense')
                    ->required()
                    ->live(),

                TextInput::make('person_name')
                    ->label('Person Name (વ્યક્તિનું નામ)')
                    ->placeholder('e.g. Ramesh, Rahul, Friend')
                    ->visible(fn ($get) => in_array($get('type'), ['udhar_given', 'udhar_received']))
                    ->required(fn ($get) => in_array($get('type'), ['udhar_given', 'udhar_received'])),

                Select::make('status')
                    ->label('Status (સ્થિતિ)')
                    ->options([
                        'pending' => 'Pending (લેવાના બાકી છે)',
                        'settled' => 'Settled (ચૂકતે થઈ ગયા)',
                    ])
                    ->default('pending')
                    ->visible(fn ($get) => $get('type') === 'udhar_given')
                    ->required(fn ($get) => $get('type') === 'udhar_given'),

                TextInput::make('category')
                    ->label('Category (કેટેગરી)')
                    ->placeholder('e.g. Brokerage, Food, Travel, Office, Personal')
                    ->visible(fn ($get) => $get('type') === 'expense'),

                TextInput::make('amount')
                    ->label('Amount (રકમ)')
                    ->numeric()
                    ->prefix('₹')
                    ->required(),

                Select::make('account_id')
                    ->label(fn ($get) => $get('type') === 'udhar_received'
                        ? 'Deposit Into Account (કયા ખાતામાં જમા થયા)'
                        : 'Paid From Account (કયા ખાતામાંથી ચૂકવ્યા)')
                    ->relationship('account', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),

                DatePicker::make('expense_date')
                    ->label('Date (તારીખ)')
                    ->required()
                    ->default(now()),

                DatePicker::make('due_date')
                    ->label('Expected Return Date (પાછા મળવાની તારીખ)')
                    ->visible(fn ($get) => $get('type') === 'udhar_given'),

                Textarea::make('notes')
                    ->label('Notes / Description')
                    ->columnSpanFull(),
            ])->columns([
                'default' => 1,
                'md' => 2,
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('expense_date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'expense' => 'Expense',
                        'udhar_given' => 'Udhar Given',
                        'udhar_received' => 'Udhar Received',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state) => match ($state) {
                        'expense' => 'gray',
                        'udhar_given' => 'warning',
                        'udhar_received' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('person_name')
                    ->label('Person')
                    ->searchable()
                    ->placeholder('—')
                    ->weight('semibold'),

                TextColumn::make('category')
                    ->label('Category')
                    ->searchable()
                    ->placeholder('—')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('account.name')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('INR')
                    ->sortable()
                    ->weight('bold')
                    ->color(fn (Expense $record) => match ($record->type) {
                        'udhar_received' => 'success',
                        'udhar_given' => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'pending' => 'Pending (બાકી)',
                        'settled' => 'Settled (ચૂકતે)',
                        default => 'Completed',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'pending' => 'danger',
                        'settled' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('notes')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('expense_date', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Filter by Type')
                    ->options([
                        'expense' => 'Regular Expense',
                        'udhar_given' => 'Udhar Given',
                        'udhar_received' => 'Udhar Received',
                    ]),
                SelectFilter::make('status')
                    ->label('Filter by Status')
                    ->options([
                        'pending' => 'Pending',
                        'settled' => 'Settled',
                        'completed' => 'Completed',
                    ]),
                SelectFilter::make('account_id')
                    ->label('Account')
                    ->relationship('account', 'name'),
            ])
            ->actions([
                Action::make('markReceived')
                    ->label('Receive Money')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Expense $record) => $record->type === 'udhar_given' && $record->status === 'pending')
                    ->form([
                        Select::make('received_account_id')
                            ->label('Deposit Into Account (કયા ખાતામાં પૈસા જમા થયા?)')
                            ->relationship('account', 'name')
                            ->required()
                            ->default(fn (Expense $record) => $record->account_id),
                        DatePicker::make('received_date')
                            ->label('Received Date (તારીખ)')
                            ->default(now())
                            ->required(),
                    ])
                    ->action(function (Expense $record, array $data) {
                        $record->update(['status' => 'settled']);

                        Expense::create([
                            'user_id' => $record->user_id,
                            'account_id' => $data['received_account_id'],
                            'type' => 'udhar_received',
                            'person_name' => $record->person_name,
                            'amount' => $record->amount,
                            'expense_date' => $data['received_date'],
                            'status' => 'completed',
                            'notes' => 'Settlement for udhar given to ' . ($record->person_name ?: 'person') . ' on ' . $record->expense_date->format('d M Y'),
                        ]);

                        Notification::make()
                            ->title('Udhar Marked as Settled!')
                            ->body('Money deposited back into account successfully.')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
