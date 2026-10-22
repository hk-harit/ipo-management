<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SourceResource\Pages;
use App\Models\Source;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Fund Management';

    protected static ?string $navigationLabel = 'Sources';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->schema([
                TextInput::make('name')
                    ->label('Name')
                    ->placeholder('e.g. Uncle A, Brother B')
                    ->required()
                    ->maxLength(255),
                Select::make('relation')
                    ->options([
                        'Uncle' => 'Uncle',
                        'Brother' => 'Brother',
                        'Father' => 'Father',
                        'Mother' => 'Mother',
                        'Friend' => 'Friend',
                        'Other' => 'Other',
                    ])
                    ->native(false),
                TextInput::make('profit_share_percent')
                    ->label('Profit Share %')
                    ->helperText('% of profit from sold IPOs funded by this source that goes to them')
                    ->numeric()
                    ->suffix('%')
                    ->default(50)
                    ->required(),
                Textarea::make('notes')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ])->columns([
                'default' => 1,
                'md' => 2,
            ]),

            Section::make('Portal Login')
                ->description('Let this source sign in at /source to see their own applications, allotments and dues. Read-only — they cannot change anything.')
                ->icon('heroicon-o-key')
                ->collapsed(fn (?Source $record) => ! $record?->portal_enabled)
                ->schema([
                    TextInput::make('mobile')
                        ->label('Mobile Number')
                        ->tel()
                        ->helperText('They can log in with this. Stored as 10 digits — "+91 98765 43210" works.')
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state) => Source::normaliseMobile($state)),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->helperText('Optional — they can log in with either mobile or email.')
                        ->unique(ignoreRecord: true),
                    TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->minLength(6)
                        ->helperText(fn (?Source $record) => $record?->password
                            ? 'A password is already set. Type here only to change it.'
                            : 'Set the password you will share with them.')
                        // Leave the stored hash untouched when the field is left blank.
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->required(fn (Get $get, ?Source $record) => $get('portal_enabled') && ! $record?->password),
                    Toggle::make('portal_enabled')
                        ->label('Portal login enabled')
                        ->helperText('Turn off to block their access without deleting anything.')
                        ->live()
                        ->default(false),
                    Placeholder::make('last_login_at')
                        ->label('Last signed in')
                        ->content(fn (?Source $record) => $record?->last_login_at?->diffForHumans() ?? 'Never')
                        ->visible(fn (?Source $record) => (bool) $record),
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
                TextColumn::make('name')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('relation')->badge(),
                TextColumn::make('total_given')
                    ->label('Total Given')
                    ->state(fn (Source $record) => $record->total_given)
                    ->money('INR')
                    ->color('success'),
                TextColumn::make('total_returned')
                    ->label('Total Returned')
                    ->state(fn (Source $record) => $record->total_returned)
                    ->money('INR')
                    ->color('gray'),
                TextColumn::make('balance_due')
                    ->label('Due')
                    ->state(fn (Source $record) => $record->balance_due)
                    ->money('INR')
                    ->weight('bold')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                TextColumn::make('total_profit_share_due')
                    ->label('Profit Share Due')
                    ->state(fn (Source $record) => $record->total_profit_share_due)
                    ->money('INR')
                    ->color('info'),
                IconColumn::make('portal_enabled')
                    ->label('Portal')
                    ->boolean()
                    ->trueIcon('heroicon-o-key')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn (Source $record) => $record->portal_enabled
                        ? 'Can sign in at /source'.($record->mobile ? ' with '.$record->mobile : '')
                        : 'No portal access'),
                TextColumn::make('last_login_at')
                    ->label('Last Login')
                    ->since()
                    ->placeholder('Never')
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_active')->label('Active'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            SourceResource\RelationManagers\FundTransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSources::route('/'),
            'create' => Pages\CreateSource::route('/create'),
            'edit' => Pages\EditSource::route('/{record}/edit'),
        ];
    }
}
