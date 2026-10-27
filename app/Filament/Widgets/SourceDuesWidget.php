<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ProfitPayoutResource;
use App\Filament\Resources\SourceResource;
use App\Models\Source;
use App\Services\DashboardSummary;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;

/**
 * What each source is owed: their money still with you, plus profit share. The
 * "earned" column follows the chosen period; paid / pending are all-time, since
 * what you still owe does not depend on which window you are looking at.
 */
class SourceDuesWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Sources — Money & Profit Share';

    public static function canView(): bool
    {
        return Source::query()->exists();
    }

    /** Profit share earned per source id in the selected period. */
    private function sharesBySource(): Collection
    {
        return (new DashboardSummary)
            ->soldApplications(...DashboardSummary::periodRange($this->filters['period'] ?? 'all'))
            ->filter(fn ($a) => $a->source_id)
            ->groupBy('source_id')
            ->map(fn (Collection $apps) => (float) $apps->sum(fn ($a) => (float) $a->source_profit_share));
    }

    public function table(Table $table): Table
    {
        $shares = $this->sharesBySource();

        return $table
            ->query(Source::query()->where('is_active', true))
            ->columns([
                TextColumn::make('name')->weight('bold')->description(fn (Source $record) => $record->relation),
                TextColumn::make('total_given')
                    ->label('Given')
                    ->state(fn (Source $record) => $record->total_given)
                    ->money('INR'),
                TextColumn::make('total_returned')
                    ->label('Returned')
                    ->state(fn (Source $record) => $record->total_returned)
                    ->money('INR'),
                TextColumn::make('balance_due')
                    ->label('Still With You')
                    ->state(fn (Source $record) => $record->balance_due)
                    ->money('INR')
                    ->weight('bold')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success'),
                TextColumn::make('profit_share')
                    ->label('Profit Share Earned')
                    ->state(fn (Source $record) => (float) ($shares[$record->id] ?? 0))
                    ->money('INR')
                    ->color('info')
                    ->description(fn (Source $record) => $record->profit_share_percent.'% default'),
                TextColumn::make('profit_share_paid')
                    ->label('Profit Paid')
                    ->state(fn (Source $record) => $record->profit_share_paid)
                    ->money('INR'),
                TextColumn::make('profit_share_pending')
                    ->label('Profit Pending')
                    ->state(fn (Source $record) => $record->profit_share_pending)
                    ->money('INR')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success'),
                TextColumn::make('total_payable')
                    ->label('Total You Owe')
                    ->state(fn (Source $record) => $record->total_payable)
                    ->money('INR')
                    ->weight('bold')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
            ])
            ->actions([
                Action::make('pay')
                    ->label('Pay Profit')
                    ->icon('heroicon-o-hand-raised')
                    ->color('success')
                    ->visible(fn (Source $record) => $record->profit_share_pending > 0)
                    ->url(fn (Source $record) => ProfitPayoutResource::getUrl('create', ['source_id' => $record->id])),
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Source $record) => SourceResource::getUrl('edit', ['record' => $record])),
            ])
            ->paginated(false);
    }
}
