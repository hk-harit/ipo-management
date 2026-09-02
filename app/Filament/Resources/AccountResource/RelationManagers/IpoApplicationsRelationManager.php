<?php

namespace App\Filament\Resources\AccountResource\RelationManagers;

use App\Filament\Support\IpoApplicationFormFields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IpoApplicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'ipoApplications';

    protected static ?string $title = 'IPO Applications';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('ipo_id')
                ->label('IPO')
                ->relationship('ipo', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('applicant_id')
                ->label('Applicant')
                ->relationship('applicant', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('source_id')
                ->label('Funded By Source')
                ->helperText('Optional — so profit on sale can be split with the right source')
                ->relationship('source', 'name')
                ->searchable()
                ->preload(),
            TextInput::make('amount')
                ->numeric()
                ->prefix('₹')
                ->required(),
            DatePicker::make('applied_date')
                ->required()
                ->default(now()),
            ...IpoApplicationFormFields::statusAndSaleSchema(),
            Textarea::make('notes')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('applied_date')->date()->sortable(),
                TextColumn::make('ipo.name')->label('IPO')->sortable(),
                TextColumn::make('applicant.name')->label('Applicant')->sortable(),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'applied' => 'warning',
                        'refunded' => 'gray',
                        'allotted', 'partial_allotted' => 'success',
                        'sold' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('return_amount')->money('INR')->label('Returned'),
                TextColumn::make('profit')
                    ->label('Profit')
                    ->state(fn ($record) => $record->profit)
                    ->money('INR')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 0 ? 'success' : 'danger'))
                    ->placeholder('—'),
            ])
            ->defaultSort('applied_date', 'desc')
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
