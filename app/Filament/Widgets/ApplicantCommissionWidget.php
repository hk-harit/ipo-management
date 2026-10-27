<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ApplicantResource;
use App\Filament\Resources\ProfitPayoutResource;
use App\Models\Applicant;
use App\Models\IpoApplication;
use App\Services\DashboardSummary;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;

/** Applicants who earn a commission on profit, with what they earned in the period. */
class ApplicantCommissionWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Applicant Commission';

    public static function canView(): bool
    {
        return IpoApplication::query()->where('applicant_commission_percent', '>', 0)->exists();
    }

    private function commissionByApplicant(): Collection
    {
        return (new DashboardSummary)
            ->soldApplications(...DashboardSummary::periodRange($this->filters['period'] ?? 'all'))
            ->groupBy('applicant_id')
            ->map(fn (Collection $apps) => [
                'earned' => (float) $apps->sum(fn ($a) => (float) $a->applicant_profit_share),
                'sold' => $apps->count(),
            ]);
    }

    public function table(Table $table): Table
    {
        $commission = $this->commissionByApplicant();

        return $table
            ->query(
                Applicant::query()->whereHas('ipoApplications', fn ($q) => $q->where('applicant_commission_percent', '>', 0))
            )
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable(),
                TextColumn::make('apps')
                    ->label('Applications with commission')
                    ->state(fn (Applicant $record) => $record->ipoApplications()->where('applicant_commission_percent', '>', 0)->count()),
                TextColumn::make('sold')
                    ->label('Sold in period')
                    ->state(fn (Applicant $record) => $commission[$record->id]['sold'] ?? 0),
                TextColumn::make('earned')
                    ->label('Commission Earned in Period')
                    ->state(fn (Applicant $record) => (float) ($commission[$record->id]['earned'] ?? 0))
                    ->money('INR')
                    ->weight('bold')
                    ->color('info'),
                TextColumn::make('commission_paid')
                    ->label('Paid')
                    ->state(fn (Applicant $record) => $record->commission_paid)
                    ->money('INR'),
                TextColumn::make('commission_pending')
                    ->label('Pending')
                    ->state(fn (Applicant $record) => $record->commission_pending)
                    ->money('INR')
                    ->weight('bold')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success'),
            ])
            ->actions([
                Action::make('pay')
                    ->label('Pay')
                    ->icon('heroicon-o-hand-raised')
                    ->color('success')
                    ->visible(fn (Applicant $record) => $record->commission_pending > 0)
                    ->url(fn (Applicant $record) => ProfitPayoutResource::getUrl('create', ['applicant_id' => $record->id])),
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Applicant $record) => ApplicantResource::getUrl('edit', ['record' => $record])),
            ])
            ->paginated([10, 25]);
    }
}
