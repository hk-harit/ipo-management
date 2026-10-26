<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\IpoApplicationResource;
use App\Filament\Support\SaleForm;
use App\Models\IpoApplication;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class AllottedAwaitingSaleWidget extends BaseWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Allotted — Awaiting Sale (વેચવાના બાકી)';

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
                TextColumn::make('ipo.name')->label('IPO')->weight('bold'),
                TextColumn::make('applicant.name')->label('Applicant'),
                TextColumn::make('account.name')->label('Account')->visibleFrom('sm'),
                TextColumn::make('amount')->label('Applied')->money('INR'),
                TextColumn::make('shares_allotted')->label('Allotted'),
                TextColumn::make('shares_remaining')
                    ->label('Left to Sell')
                    ->state(fn (IpoApplication $record) => $record->shares_remaining)
                    ->description(fn (IpoApplication $record) => $record->shares_sold ? "{$record->shares_sold} already sold" : null)
                    ->badge()
                    ->color('warning'),
                TextColumn::make('status')->badge()->color('success')->visibleFrom('md'),
            ])
            ->actions([
                Action::make('record_sale')
                    ->label('Record Sale')
                    ->icon('heroicon-o-currency-rupee')
                    ->color('primary')
                    ->visible(fn (IpoApplication $record) => $record->shares_remaining > 0)
                    ->modalHeading(fn (IpoApplication $record) => "Record Sale — {$record->ipo?->name} ({$record->applicant?->name})")
                    ->form(fn (IpoApplication $record) => SaleForm::schema($record))
                    ->action(function (IpoApplication $record, array $data) {
                        $sale = $record->sales()->create($data);
                        $record->refresh();

                        Notification::make()
                            ->success()
                            ->title($record->status === 'sold' ? 'Fully sold — marked as Sold' : 'Sale recorded')
                            ->body('₹'.number_format((float) $sale->net_amount, 2).' credited to '.($record->account?->name ?? 'the account').'.')
                            ->send();
                    }),
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (IpoApplication $record) => IpoApplicationResource::getUrl('edit', ['record' => $record])),
            ])
            ->paginated(false);
    }
}
