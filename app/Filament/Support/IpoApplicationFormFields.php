<?php

namespace App\Filament\Support;

use App\Models\Source;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Database\Eloquent\Model;
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
                    'not_allotted' => 'Not allotted (refund pending)',
                    'refunded' => 'Refunded (money is back)',
                    'allotted' => 'Allotted',
                    'partial_allotted' => 'Partially Allotted',
                    'sold' => 'Sold',
                ])
                ->required()
                ->native(false)
                ->default('applied')
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                    if ($state === 'refunded') {
                        $amount = $get('amount');
                        if (blank($get('return_amount')) && filled($amount)) {
                            $set('return_amount', $amount);
                        }
                        if (blank($get('return_date'))) {
                            $set('return_date', now()->format('Y-m-d'));
                        }
                        $set('shares_allotted', null);
                    } elseif ($state === 'not_allotted') {
                        $set('return_amount', null);
                        $set('return_date', null);
                        $set('shares_allotted', 0);
                    } elseif ($state === 'applied') {
                        $set('return_amount', null);
                        $set('return_date', null);
                        $set('shares_allotted', null);
                    }
                }),

            // Decided up front when you apply — this is the deal you made with the
            // source/applicant, not something you work out later at sale time.
            TextInput::make('source_share_percent_override')
                ->label('Source Share % (this application)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->live()
                ->helperText(fn (Get $get) => self::sourceDefaultHelperText($get))
                ->visible(fn (Get $get) => filled($get('source_id'))),

            TextInput::make('applicant_commission_percent')
                ->label('Applicant Commission %')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->rules([
                    fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                        $source = filled($get('source_id')) ? self::sourcePercent($get) : 0.0;
                        if ($source + (float) $value > 100) {
                            $fail('Source share + applicant commission cannot be more than 100%.');
                        }
                    },
                ])
                ->suffix('%')
                ->default(0)
                ->live(),

            TextInput::make('shares_allotted')
                ->label('Shares Allotted')
                ->numeric()
                ->visible(fn (Get $get) => in_array($get('status'), ['allotted', 'partial_allotted', 'sold'])),

            Placeholder::make('sales_hint')
                ->label('Sales (વેચાણ)')
                ->content('Record each sale — date, shares, price and charges — in the Sales tab below. Status, shares sold and profit update from those.')
                ->visible(fn (Get $get, ?Model $record) => $get('status') === 'sold' && (bool) $record?->sales()->exists()),
            TextInput::make('shares_sold')
                ->numeric()
                ->minValue(0)
                ->rules([
                    fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                        $allotted = $get('shares_allotted');
                        if (filled($allotted) && (float) $value > (float) $allotted) {
                            $fail('Shares sold cannot be more than shares allotted ('.$allotted.').');
                        }
                    },
                ])
                ->live(onBlur: true)
                ->visible(fn (Get $get, ?Model $record) => $get('status') === 'sold' && ! $record?->sales()->exists()),

            TextInput::make('sale_price_per_share')
                ->label('Sale Price / Share')
                ->numeric()
                ->prefix('₹')
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set) {
                    self::autoFillReturnAmount($get, $set);
                })
                ->visible(fn (Get $get, ?Model $record) => $get('status') === 'sold' && ! $record?->sales()->exists()),

            TextInput::make('return_amount')
                ->label(fn (Get $get) => match ($get('status')) {
                    'sold' => 'Sale Proceeds (Total)',
                    'refunded' => 'Refund Amount (credited to account)',
                    default => 'Amount Returned to Account',
                })
                ->numeric()
                ->minValue(0)
                ->prefix('₹')
                ->live()
                ->helperText(fn (Get $get) => $get('status') === 'refunded' ? 'Auto-filled with applied amount. This amount is credited back to the bank account.' : null)
                ->visible(fn (Get $get) => in_array($get('status'), ['refunded', 'partial_allotted', 'sold'])),

            DatePicker::make('return_date')
                ->label(fn (Get $get) => $get('status') === 'sold' ? 'Sale Date' : 'Refund / Return Date')
                ->default(fn (Get $get) => in_array($get('status'), ['refunded', 'partial_allotted']) ? now() : null)
                ->helperText(fn (Get $get) => $get('status') === 'refunded' ? 'Date when mandate was released / refund came back' : null)
                ->visible(fn (Get $get) => in_array($get('status'), ['refunded', 'partial_allotted', 'sold'])),

            Placeholder::make('profit_split_preview')
                ->label('Profit Split (auto-calculated)')
                ->live()
                ->content(fn (Get $get) => self::profitSplitPreview($get))
                ->visible(fn (Get $get, ?Model $record) => $get('status') === 'sold' && ! $record?->sales()->exists())
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

    /** The % the chosen source gets: this application's override, else the source's default. */
    private static function sourcePercent(Get $get): float
    {
        if (filled($get('source_share_percent_override'))) {
            return (float) $get('source_share_percent_override');
        }

        return (float) (Source::find($get('source_id'))?->profit_share_percent ?? 0);
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
