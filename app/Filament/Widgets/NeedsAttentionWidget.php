<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\IpoApplicationResource;
use App\Models\IpoApplication;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class NeedsAttentionWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Needs Attention — still "Applied" after 10+ days';

    public static function canView(): bool
    {
        // Don't take up space on the dashboard when there's nothing to flag.
        return IpoApplication::where('status', 'applied')
            ->where('applied_date', '<=', now()->subDays(10))
            ->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                IpoApplication::query()
                    ->where('status', 'applied')
                    ->where('applied_date', '<=', now()->subDays(10))
                    ->with(['ipo', 'applicant', 'account'])
            )
            ->columns([
                TextColumn::make('applied_date')->date()->label('Applied On')->sortable(),
                TextColumn::make('ipo.name')->label('IPO'),
                TextColumn::make('applicant.name')->label('Applicant'),
                TextColumn::make('account.name')->label('Account'),
                TextColumn::make('amount')->money('INR'),
                TextColumn::make('days_pending')
                    ->label('Days Pending')
                    ->state(fn (IpoApplication $record) => now()->diffInDays($record->applied_date))
                    ->badge()
                    ->color('danger'),
            ])
            ->actions([
                Action::make('update')
                    ->label('Update Result')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (IpoApplication $record) => IpoApplicationResource::getUrl('edit', ['record' => $record])),
            ])
            ->paginated(false);
    }
}
