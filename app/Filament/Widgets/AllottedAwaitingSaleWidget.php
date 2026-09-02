<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\IpoApplicationResource;
use App\Models\IpoApplication;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class AllottedAwaitingSaleWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Allotted — Awaiting Sale';

    public static function canView(): bool
    {
        return IpoApplication::whereIn('status', ['allotted', 'partial_allotted'])->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                IpoApplication::query()
                    ->whereIn('status', ['allotted', 'partial_allotted'])
                    ->with(['ipo', 'applicant', 'account'])
            )
            ->columns([
                TextColumn::make('ipo.name')->label('IPO'),
                TextColumn::make('applicant.name')->label('Applicant'),
                TextColumn::make('account.name')->label('Account'),
                TextColumn::make('amount')->label('Applied')->money('INR'),
                TextColumn::make('shares_allotted')->label('Shares Allotted'),
                TextColumn::make('status')->badge()->color('success'),
            ])
            ->actions([
                Action::make('record_sale')
                    ->label('Record Sale')
                    ->icon('heroicon-o-currency-rupee')
                    ->color('primary')
                    ->url(fn (IpoApplication $record) => IpoApplicationResource::getUrl('edit', ['record' => $record])),
            ])
            ->paginated(false);
    }
}
