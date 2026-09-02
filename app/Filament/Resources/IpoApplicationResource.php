<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IpoApplicationResource\Pages;
use App\Filament\Support\AllotmentCheckBulkAction;
use App\Filament\Support\IpoApplicationFormFields;
use App\Filament\Support\IpoPicker;
use App\Models\IpoApplication;
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

class IpoApplicationResource extends Resource
{
    protected static ?string $model = IpoApplication::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'IPO Management';

    protected static ?string $navigationLabel = 'All Applications';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->schema([
                IpoPicker::select(),
                IpoPicker::summary(),
                Select::make('applicant_id')
                    ->label('Applicant')
                    ->relationship('applicant', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('account_id')
                    ->label('Funded From Account')
                    ->relationship('account', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('source_id')
                    ->label('Funded By Source')
                    ->helperText('Optional — set this so profit on sale can be split with the right source')
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
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('applied_date')->date()->sortable(),
                TextColumn::make('ipo.name')->label('IPO')->searchable()->sortable(),
                TextColumn::make('applicant.name')->label('Applicant')->searchable()->sortable(),
                TextColumn::make('account.name')->label('Account')->searchable()->sortable(),
                TextColumn::make('source.name')->label('Source')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'applied' => 'warning',
                        'refunded' => 'gray',
                        'allotted', 'partial_allotted' => 'success',
                        'sold' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('return_amount')->money('INR')->label('Returned')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('profit')
                    ->label('Profit')
                    ->state(fn (IpoApplication $record) => $record->profit)
                    ->money('INR')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 0 ? 'success' : 'danger'))
                    ->placeholder('—'),
            ])
            ->defaultSort('applied_date', 'desc')
            ->filters([
                SelectFilter::make('ipo_id')->label('IPO')->relationship('ipo', 'name'),
                SelectFilter::make('applicant_id')->label('Applicant')->relationship('applicant', 'name'),
                SelectFilter::make('account_id')->label('Account')->relationship('account', 'name'),
                SelectFilter::make('source_id')->label('Source')->relationship('source', 'name'),
                SelectFilter::make('status')->options([
                    'applied' => 'Applied',
                    'refunded' => 'Refunded',
                    'allotted' => 'Allotted',
                    'partial_allotted' => 'Partially Allotted',
                    'sold' => 'Sold',
                ]),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    AllotmentCheckBulkAction::make(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIpoApplications::route('/'),
            'create' => Pages\CreateIpoApplication::route('/create'),
            'edit' => Pages\EditIpoApplication::route('/{record}/edit'),
        ];
    }
}
