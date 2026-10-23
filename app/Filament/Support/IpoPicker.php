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
            ->hiddenLabel()
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

        $items = [];

        if ($ipo->price_band) {
            $items[] = [
                'label' => 'Price Band',
                'value' => e($ipo->price_band),
                'sub' => null,
            ];
        }

        if ($ipo->lot_amount !== null) {
            $items[] = [
                'label' => '1 Lot (Cost & Shares)',
                'value' => '₹'.number_format($ipo->lot_amount, 2),
                'sub' => $ipo->lot_size ? '('.number_format($ipo->lot_size).' shares)' : null,
            ];
        }

        if ($ipo->gmp !== null) {
            $gain = $ipo->estimated_gain_per_lot;
            $gmpFormatted = '₹'.number_format((float) $ipo->gmp, 2);
            $pct = $ipo->gmp_percent !== null ? ' (+'.number_format((float) $ipo->gmp_percent, 2).'%)' : '';
            $gainText = $gain !== null ? '≈ ₹'.number_format($gain, 0).' a lot' : null;

            $items[] = [
                'label' => 'Grey Market Premium (GMP)',
                'value' => '<span style="color: #059669; font-weight: 700;">'.$gmpFormatted.$pct.'</span>',
                'sub' => $gainText,
            ];
        }

        if ($ipo->subscription_retail !== null) {
            $subTextParts = [];
            if ($ipo->subscription_total !== null) {
                $subTextParts[] = 'Total: '.number_format((float) $ipo->subscription_total, 2).'x';
            }
            if ($ipo->subscription_applications) {
                $subTextParts[] = number_format($ipo->subscription_applications).' apps';
            }

            $items[] = [
                'label' => 'Retail Subscribed',
                'value' => number_format((float) $ipo->subscription_retail, 2).'x',
                'sub' => $subTextParts ? '('.implode(' · ', $subTextParts).')' : null,
            ];
        }

        if ($ipo->open_date || $ipo->close_date) {
            $dateRange = trim(($ipo->open_date?->format('d M Y') ?? '?').' – '.($ipo->close_date?->format('d M Y') ?? '?'));
            $items[] = [
                'label' => 'Issue Dates',
                'value' => $dateRange,
                'sub' => $ipo->listing_date ? 'Lists: '.$ipo->listing_date->format('d M Y') : null,
            ];
        }

        if ($items === []) {
            return 'No market data for this IPO yet — run "Sync IPO list" on the IPOs page.';
        }

        $html = '<div class="ipo-summary-box" style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 1rem; padding: 1rem 1.25rem; margin: 0.35rem 0 0.5rem 0; width: 100%; box-sizing: border-box;">';

        $html .= '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; padding-bottom: 0.5rem; border-bottom: 1px solid rgba(16, 185, 129, 0.15); flex-wrap: wrap; gap: 0.5rem;">';
        $html .= '<div style="display: flex; align-items: center; gap: 0.5rem;">'
            .'<span style="display: inline-block; width: 0.5rem; height: 0.5rem; border-radius: 9999px; background-color: #10b981;"></span>'
            .'<span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #059669;" class="dark:text-emerald-400">Live IPO Market Intelligence</span>'
            .'</div>';
        $html .= '<span style="font-size: 0.7rem; font-weight: 500; color: #64748b;" class="dark:text-gray-400">Market Snapshot</span>';
        $html .= '</div>';

        $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.875rem 1.25rem;">';

        foreach ($items as $item) {
            $html .= '<div style="display: flex; flex-direction: column; gap: 0.2rem; min-width: 0;">';
            $html .= '<span style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b;" class="dark:text-gray-400">'.e($item['label']).'</span>';
            $html .= '<div style="font-size: 0.9rem; font-weight: 600; color: #0f172a; display: flex; align-items: baseline; flex-wrap: wrap; gap: 0.35rem;" class="dark:text-white">';
            $html .= '<span>'.$item['value'].'</span>';
            if ($item['sub']) {
                $html .= '<span style="font-size: 0.75rem; font-weight: 400; color: #64748b;" class="dark:text-gray-400">'.e($item['sub']).'</span>';
            }
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';

        if ($ipo->gmp !== null) {
            $html .= '<div style="margin-top: 0.75rem; padding-top: 0.5rem; border-top: 1px dashed rgba(16, 185, 129, 0.2);">'
                .'<p style="font-size: 0.7rem; color: #64748b; margin: 0; line-height: 1.3;" class="dark:text-gray-400">'
                .'Grey market premium is an unofficial, unregulated quote — a signal about listing gains, not a promise.'
                .'</p></div>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }
}
