<?php

namespace App\Filament\Resources\IpoApplicationResource\RelationManagers;

use App\Filament\Support\SaleForm;
use App\Models\IpoApplication;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SalesRelationManager extends RelationManager
{
    protected static string $relationship = 'sales';

    protected static ?string $title = 'Sales (વેચાણ)';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return in_array($ownerRecord->status, ['allotted', 'partial_allotted', 'sold'], true);
    }

    public function form(Form $form): Form
    {
        /** @var IpoApplication $application */
        $application = $this->getOwnerRecord();

        return $form->schema(SaleForm::schema($application, $form->getRecord()))->columns(2);
    }

    public function table(Table $table): Table
    {
        /** @var IpoApplication $application */
        $application = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('id')
            ->description(fn () => sprintf(
                'Sold %d of %d share(s) · charges ₹%s · net received ₹%s',
                (int) $application->fresh()->shares_sold,
                (int) $application->shares_allotted,
                number_format($application->fresh()->sales_charges, 2),
                number_format($application->fresh()->sales_net, 2),
            ))
            ->columns([
                TextColumn::make('sale_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('shares_sold')->label('Shares'),
                TextColumn::make('price_per_share')->label('Price')->money('INR'),
                TextColumn::make('gross_amount')->label('Gross')->money('INR'),
                TextColumn::make('charges')->money('INR')->color('danger'),
                TextColumn::make('net_amount')->label('Net')->money('INR')->weight('bold'),
                TextColumn::make('notes')->limit(30)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sale_date', 'desc')
            ->headerActions([
                CreateAction::make()->label('Record Sale')->visible(fn () => $application->fresh()->shares_remaining > 0),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
