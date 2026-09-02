<?php

namespace App\Filament\Support;

use App\Models\Ipo;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;

/**
 * The "which IPO am I applying for" control, shared by the application resource
 * and the bulk-apply page.
 *
 * Picking an IPO is the moment the decision gets made, so everything that
 * decides it travels with the field: the price band, what one lot costs, the
 * grey market premium and how far retail has been subscribed. Otherwise the
 * numbers live on a different screen from the choice they inform.
 */
class IpoPicker
{
    public static function select(string $name = 'ipo_id'): Select
    {
        return Select::make($name)
            ->label('IPO')
            ->options(fn () => static::options())
            ->required()
            ->searchable()
            ->live()
            ->helperText('Open and upcoming IPOs first, then the rest.');
    }

    /** A read-only summary of whichever IPO is currently selected. */
    public static function summary(string $dependsOn = 'ipo_id'): Placeholder
    {
        return Placeholder::make('ipo_summary')
            ->label('')
            ->content(fn (Get $get) => static::summaryFor($get($dependsOn)))
            ->visible(fn (Get $get) => filled($get($dependsOn)))
            ->columnSpanFull();
    }

    /**
     * Live issues float to the top — those are the ones actually being applied
     * for — with the rest still reachable underneath for backdated entries.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return Ipo::query()
            ->orderByUrgency()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Ipo $ipo) => [$ipo->id => static::label($ipo)])
            ->all();
    }

    private static function label(Ipo $ipo): string
    {
        $parts = [$ipo->name];

        if ($ipo->issue_type === 'SME') {
            $parts[] = '[SME]';
        }

        if ($ipo->price_band) {
            $parts[] = '· '.$ipo->price_band;
        }

        if ($ipo->gmp !== null && (float) $ipo->gmp != 0.0) {
            $parts[] = '· GMP ₹'.number_format((float) $ipo->gmp, 0);
        }

        if (in_array($ipo->status, ['open', 'upcoming'], true)) {
            $parts[] = '· '.ucfirst($ipo->status);
        }

        return implode(' ', $parts);
    }

    private static function summaryFor(mixed $ipoId): HtmlString|string
    {
        $ipo = Ipo::find($ipoId);

        if (! $ipo) {
            return '';
        }

        $rows = [];

        if ($ipo->price_band) {
            $rows[] = ['Price band', $ipo->price_band];
        }

        if ($ipo->lot_amount !== null) {
            $rows[] = [
                '1 lot',
                '₹'.number_format($ipo->lot_amount, 2).
                ($ipo->lot_size ? ' <span class="opacity-60">('.$ipo->lot_size.' shares)</span>' : ''),
            ];
        }

        if ($ipo->gmp !== null) {
            $gain = $ipo->estimated_gain_per_lot;

            $rows[] = [
                'Grey market premium',
                '<strong>₹'.number_format((float) $ipo->gmp, 2).'</strong>'.
                ($ipo->gmp_percent !== null ? ' ('.number_format((float) $ipo->gmp_percent, 2).'%)' : '').
                ($gain !== null ? ' <span class="opacity-60">≈ ₹'.number_format($gain, 0).' a lot</span>' : ''),
            ];
        }

        if ($ipo->subscription_retail !== null) {
            $rows[] = [
                'Retail subscribed',
                '<strong>'.number_format((float) $ipo->subscription_retail, 2).'x</strong>'.
                ($ipo->subscription_total !== null ? ' <span class="opacity-60">· total '.number_format((float) $ipo->subscription_total, 2).'x</span>' : '').
                ($ipo->subscription_applications ? ' <span class="opacity-60">· '.number_format($ipo->subscription_applications).' applications</span>' : ''),
            ];
        }

        if ($ipo->open_date || $ipo->close_date) {
            $rows[] = [
                'Dates',
                trim(($ipo->open_date?->format('d M Y') ?? '?').' – '.($ipo->close_date?->format('d M Y') ?? '?')).
                ($ipo->listing_date ? ' <span class="opacity-60">· lists '.$ipo->listing_date->format('d M Y').'</span>' : ''),
            ];
        }

        if ($rows === []) {
            return 'No market data for this IPO yet — run "Sync IPO list" on the IPOs page.';
        }

        $html = '<div class="grid gap-1 text-sm">';

        foreach ($rows as [$label, $value]) {
            $html .= '<div class="flex gap-2"><span class="w-44 shrink-0 text-gray-500 dark:text-gray-400">'
                .e($label).'</span><span class="text-gray-950 dark:text-white">'.$value.'</span></div>';
        }

        $html .= '</div>';

        if ($ipo->gmp !== null) {
            $html .= '<p class="mt-2 text-xs text-gray-400">Grey market premium is an unofficial, '
                .'unregulated quote — a signal about listing gains, not a promise.</p>';
        }

        return new HtmlString($html);
    }
}
