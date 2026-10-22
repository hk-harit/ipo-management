<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApplicantResource\Pages;
use App\Models\Applicant;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class ApplicantResource extends Resource
{
    protected static ?string $model = Applicant::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'IPO Management';

    protected static ?string $navigationLabel = 'Applicants';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('relation')
                    ->options([
                        'friend' => 'Friend',
                        'cousin' => 'Cousin',
                        'family' => 'Family',
                        'other' => 'Other',
                    ])
                    ->required()
                    ->native(false),
                TextInput::make('pan_number')->label('PAN Number')->maxLength(255),
                TextInput::make('demat_account')->label('Demat Account')->maxLength(255),
                Textarea::make('notes')->columnSpanFull(),
                Toggle::make('is_active')->default(true),
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
                TextColumn::make('pan_number')->label('PAN')->toggleable(),
                TextColumn::make('ipo_applications_count')->counts('ipoApplications')->label('Applications'),
                TextColumn::make('total_commission_due')
                    ->label('Commission Due')
                    ->state(fn (Applicant $record) => $record->total_commission_due)
                    ->money('INR')
                    ->color('info')
                    ->placeholder('—'),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->defaultSort('name')
            ->actions([
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
            'index' => Pages\ListApplicants::route('/'),
            'create' => Pages\CreateApplicant::route('/create'),
            'edit' => Pages\EditApplicant::route('/{record}/edit'),
        ];
    }
}
