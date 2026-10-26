<?php

namespace App\Filament\Support;

use App\Models\ApplicationSale;
use App\Models\IpoApplication;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;

/**
 * The "record a sale" fields, shared by the Sales tab on an application and the
 * Record Sale button on the dashboard so both validate the same way.
 */
class SaleForm
{
    public static function schema(IpoApplication $application, ?ApplicationSale $editing = null): array
    {
        // Shares still free to sell — counting the sale being edited as free again.
        $remaining = $application->shares_remaining + (int) $editing?->shares_sold;
        $knownShares = (int) $application->shares_allotted > 0;

        return [
            DatePicker::make('sale_date')
                ->label('Sale Date (વેચાણ તારીખ)')
                ->required()
                ->default(now()),

            TextInput::make('shares_sold')
                ->label('Shares Sold (વેચેલા શેર)')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue($knownShares ? $remaining : null)
                ->default($knownShares ? $remaining : null)
                ->helperText($knownShares ? "{$remaining} share(s) left to sell" : 'Set Shares Allotted on the application first.')
                ->required()
                ->live(onBlur: true),

            TextInput::make('price_per_share')
                ->label('Sale Price / Share (ભાવ)')
                ->numeric()
                ->minValue(0.01)
                ->prefix('₹')
                ->required()
                ->live(onBlur: true),

            TextInput::make('charges')
                ->label('Charges (બ્રોકરેજ/DP/STT)')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->prefix('₹')
                ->helperText('Everything it cost to sell this lot')
                ->live(onBlur: true),

            Placeholder::make('preview')
                ->label('You receive (net)')
                ->content(fn (Get $get) => self::preview($get))
                ->columnSpanFull(),

            Textarea::make('notes')->columnSpanFull(),
        ];
    }

    private static function preview(Get $get): HtmlString
    {
        $shares = (int) $get('shares_sold');
        $price = (float) $get('price_per_share');
        $charges = (float) $get('charges');

        if ($shares <= 0 || $price <= 0) {
            return new HtmlString('<span class="text-gray-500">Enter shares and price.</span>');
        }

        $gross = round($shares * $price, 2);
        $net = round($gross - $charges, 2);

        return new HtmlString(
            '₹'.number_format($gross, 2).' − ₹'.number_format($charges, 2).' charges = <strong>₹'.number_format($net, 2).'</strong>'
        );
    }
}
