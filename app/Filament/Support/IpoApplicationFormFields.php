<?php

namespace App\Filament\Support;

use App\Models\Source;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\HtmlString;

/**
 * The status → sale details → profit-split part of the IPO Application form.
 * Shared by the top-level resource and both relation managers so the "record
 * a sale and see the 3-way profit split" workflow behaves identically everywhere.
 */
class IpoApplicationFormFields
{
    public static function statusAndSaleSchema(): array
    {
        return [
            Select::make('status')
                ->options([
                    'applied' => 'Applied',
                    'refunded' => 'Refunded',
                    'allotted' => 'Allotted',
                    'partial_allotted' => 'Partially Allotted',
                    'sold' => 'Sold',
                ])
                ->required()
                ->native(false)
                ->default('applied')
                ->live(),

            // Decided up front when you apply — this is the deal you made with the
            // source/applicant, not something you work out later at sale time.
            TextInput::make('source_share_percent_override')
                ->label('Source Share % (this application)')
                ->numeric()
                ->suffix('%')
                ->live()
                ->helperText(fn (Get $get) => self::sourceDefaultHelperText($get))
                ->visible(fn (Get $get) => filled($get('source_id'))),

            TextInput::make('applicant_commission_percent')
                ->label('Applicant Commission %')
                ->numeric()
                ->suffix('%')
                ->default(0)
                ->live(),

            TextInput::make('shares_allotted')
                ->label('Shares Allotted')
                ->numeric()
                ->visible(fn (Get $get) => in_array($get('status'), ['allotted', 'partial_allotted', 'sold'])),

            TextInput::make('shares_sold')
                ->numeric()
                ->live(onBlur: true)
                ->visible(fn (Get $get) => $get('status') === 'sold'),

            TextInput::make('sale_price_per_share')
                ->label('Sale Price / Share')
                ->numeric()
                ->prefix('₹')
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set) {
                    self::autoFillReturnAmount($get, $set);
                })
                ->visible(fn (Get $get) => $get('status') === 'sold'),

            TextInput::make('return_amount')
                ->label(fn (Get $get) => $get('status') === 'sold' ? 'Sale Proceeds (Total)' : 'Amount Returned to Account')
                ->numeric()
                ->prefix('₹')
                ->live()
                ->visible(fn (Get $get) => in_array($get('status'), ['refunded', 'partial_allotted', 'sold'])),

            DatePicker::make('return_date')
                ->label(fn (Get $get) => $get('status') === 'sold' ? 'Sale Date' : 'Return Date')
                ->visible(fn (Get $get) => in_array($get('status'), ['refunded', 'partial_allotted', 'sold'])),

            Placeholder::make('profit_split_preview')
                ->label('Profit Split (auto-calculated)')
                ->live()
                ->content(fn (Get $get) => self::profitSplitPreview($get))
                ->visible(fn (Get $get) => $get('status') === 'sold')
                ->columnSpanFull(),
        ];
    }

    private static function autoFillReturnAmount(Get $get, Set $set): void
    {
        $shares = (float) ($get('shares_sold') ?? 0);
        $price = (float) ($get('sale_price_per_share') ?? 0);

        if ($shares > 0 && $price > 0) {
            $set('return_amount', round($shares * $price, 2));
        }
    }

    private static function sourceDefaultHelperText(Get $get): string
    {
        $sourceId = $get('source_id');

        if (! $sourceId) {
            return 'Leave blank if no source is funding this application.';
        }

        $default = Source::find($sourceId)?->profit_share_percent;

        return $default !== null
            ? "Leave blank to use this source's default: {$default}%"
            : 'Leave blank to use the source default.';
    }

    private static function profitSplitPreview(Get $get): HtmlString
    {
        if ($get('status') !== 'sold') {
            return new HtmlString('<span class="text-gray-500">Mark status as Sold to see the split.</span>');
        }

        $amount = (float) ($get('amount') ?? 0);
        $returnAmount = (float) ($get('return_amount') ?? 0);
        $profit = $returnAmount - $amount;

        if ($returnAmount <= 0) {
            return new HtmlString('<span class="text-gray-500">Enter Sale Proceeds to see the split.</span>');
        }

        $sourceId = $get('source_id');
        $sourcePercent = $get('source_share_percent_override');
        $sourcePercent = filled($sourcePercent)
            ? (float) $sourcePercent
            : (float) ($sourceId ? Source::find($sourceId)?->profit_share_percent : 0);

        $applicantPercent = (float) ($get('applicant_commission_percent') ?? 0);

        $sourceShare = round($profit * $sourcePercent / 100, 2);
        $applicantShare = round($profit * $applicantPercent / 100, 2);
        $yourShare = round($profit - $sourceShare - $applicantShare, 2);

        $profitColor = $profit >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400';
        $profitFmt = '₹'.number_format($profit, 2);
        $sourceShareFmt = '₹'.number_format($sourceShare, 2);
        $applicantShareFmt = '₹'.number_format($applicantShare, 2);
        $yourShareFmt = '₹'.number_format($yourShare, 2);

        return new HtmlString(<<<HTML
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 text-sm">
                <div><span class="text-gray-500">Profit</span><br><span class="font-semibold {$profitColor}">{$profitFmt}</span></div>
                <div><span class="text-gray-500">Source Gets ({$sourcePercent}%)</span><br><span class="font-semibold">{$sourceShareFmt}</span></div>
                <div><span class="text-gray-500">Applicant Gets ({$applicantPercent}%)</span><br><span class="font-semibold">{$applicantShareFmt}</span></div>
                <div><span class="text-gray-500">You Get</span><br><span class="font-semibold text-primary-600 dark:text-primary-400">{$yourShareFmt}</span></div>
            </div>
        HTML);
    }
}
