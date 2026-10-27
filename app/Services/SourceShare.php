<?php

namespace App\Services;

use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Models\Source;
use Illuminate\Support\Collection;

/**
 * What a source is told on WhatsApp: how much they gave, how many IPOs were applied
 * with their money (and how much in each), and who it was applied for in the IPO being
 * looked at. Builds both the plain-text message and the figures the image card draws,
 * so the two can never disagree.
 *
 * The applicants' commission is deliberately left out — that is yours, not something
 * to forward to a source. PAN numbers appear on the image only, not in the text.
 */
class SourceShare
{
    /**
     * @param  Collection<int, IpoApplication>  $applications  every application funded by this source
     * @return array<string, mixed>
     */
    public static function build(Source $source, Collection $applications, ?Ipo $focusIpo, ?float $lotAmount): array
    {
        // With an IPO selected the statement is about that IPO alone; with none selected
        // ("All IPOs") it summarises every IPO funded with this source's money.
        $scoped = $focusIpo ? $applications->where('ipo_id', $focusIpo->id) : $applications;

        $ipos = $scoped
            ->groupBy('ipo_id')
            ->map(fn (Collection $apps) => [
                'name' => $apps->first()->ipo?->name ?? 'IPO',
                'apps' => $apps->count(),
                'amount' => (float) $apps->sum('amount'),
                'last' => $apps->max('applied_date'),
            ])
            ->sortByDesc('last')
            ->values();

        $focusRows = $focusIpo
            ? $applications->where('ipo_id', $focusIpo->id)->sort(fn (IpoApplication $x, IpoApplication $y) => [IpoApplication::resultRank($x->status), -(float) $x->amount] <=> [IpoApplication::resultRank($y->status), -(float) $y->amount])->map(fn (IpoApplication $a) => [
                'name' => $a->applicant?->name ?? '—',
                'pan' => $a->applicant?->pan_number,
                'lots' => $lotAmount ? max(1, (int) round((float) $a->amount / $lotAmount)) : null,
                'amount' => (float) $a->amount,
                'status' => $a->status,
                'shares' => $a->shares_allotted ? (int) $a->shares_allotted : null,
            ])->values()
            : collect();

        $data = [
            'name' => $source->name,
            'phone' => static::whatsappNumber($source->mobile),
            'date' => now()->format('d M Y'),
            'given' => $source->total_given,
            'returned' => $source->total_returned,
            'due' => $source->balance_due,
            'profit_earned' => $source->total_profit_share_due,
            'profit_pending' => $source->profit_share_pending,
            'ipos' => $ipos,
            'ipo_count' => $ipos->count(),
            'apps_total' => (int) $ipos->sum('apps'),
            'amount_total' => (float) $ipos->sum('amount'),
            'focus_name' => $focusIpo?->name,
            'focus_rows' => $focusRows,
            'focus_amount' => (float) $focusRows->sum('amount'),
            'allotted' => $focusRows->whereIn('status', ['allotted', 'partial_allotted', 'sold'])->count(),
            'not_allotted' => $focusRows->whereIn('status', IpoApplication::NOT_ALLOTTED)->count(),
            'waiting' => $focusRows->where('status', 'applied')->count(),
        ];

        $data['text'] = static::text($data);
        $data['filename'] = 'ipo-statement-'.str($source->name)->slug().'-'.now()->format('Y-m-d').'.png';

        return $data;
    }

    /** 10-digit Indian mobile -> the number wa.me wants (91XXXXXXXXXX); null if there is none. */
    public static function whatsappNumber(?string $mobile): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $mobile);

        return strlen($digits) === 10 ? '91'.$digits : null;
    }

    /** A result that is in: "✅ Allotted (12 shares)" or "❌ Not allotted"; nothing while it is still awaited. */
    public static function resultMark(string $status, ?int $shares): string
    {
        return match ($status) {
            'allotted', 'partial_allotted', 'sold' => '✅ Allotted'.($shares ? ' ('.$shares.' '.($shares === 1 ? 'share' : 'shares').')' : ''),
            'not_allotted', 'refunded' => '❌ Not allotted',
            default => '',
        };
    }

    /** Rupees in Indian grouping, no paise: 1452086 -> ₹14,52,086. */
    public static function inr(float $value): string
    {
        $digits = (string) (int) round(abs($value));

        if (strlen($digits) > 3) {
            $digits = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($digits, 0, -3)).','.substr($digits, -3);
        }

        return ($value < 0 ? '−' : '').'₹'.$digits;
    }

    private static function money(float $value): string
    {
        return static::inr($value);
    }

    /** The WhatsApp message: short sections with *bold* headings, in plain English. */
    public static function text(array $d): string
    {
        $lines = [
            '*IPO statement — '.$d['name'].'*',
            $d['date'],
            '',
            '*Money*',
            'You gave: '.static::money($d['given']),
        ];

        if ($d['returned'] > 0) {
            $lines[] = 'Paid back: '.static::money($d['returned']);
        }

        $lines[] = 'Still with us: '.static::money($d['due']);

        if ($d['profit_earned'] != 0.0) {
            $lines[] = 'Your profit share: '.static::money($d['profit_earned']).' (pending '.static::money($d['profit_pending']).')';
        }

        $lines[] = '';

        if ($d['focus_name']) {
            // One IPO: its total and who it was applied for.
            $lines[] = '*'.$d['focus_name'].' — applied*';
            $lines[] = 'Total '.$d['apps_total'].' '.($d['apps_total'] === 1 ? 'application' : 'applications').' · '.static::money($d['amount_total']);
        } else {
            $lines[] = '*Applied in IPOs*';
            $lines[] = 'Total '.$d['ipo_count'].' '.($d['ipo_count'] === 1 ? 'IPO' : 'IPOs').' · '.$d['apps_total'].' applications · '.static::money($d['amount_total']);

            foreach ($d['ipos'] as $ipo) {
                $lines[] = '• '.$ipo['name'].' — '.$ipo['apps'].' '.($ipo['apps'] === 1 ? 'application' : 'applications').' · '.static::money($ipo['amount']);
            }
        }

        if ($d['focus_name'] && $d['focus_rows']->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '*Applied in whose name*';

            if ($d['allotted'] + $d['not_allotted'] > 0) {
                $lines[] = 'Allotted: '.$d['allotted'].' · Not allotted: '.$d['not_allotted'].($d['waiting'] ? ' · Pending: '.$d['waiting'] : '');
            }

            foreach ($d['focus_rows'] as $i => $row) {
                $mark = static::resultMark($row['status'], $row['shares']);
                $lines[] = ($i + 1).'. '.$row['name'].($row['lots'] ? ' — '.$row['lots'].($row['lots'] === 1 ? ' lot' : ' lots') : '').' — '.static::money($row['amount']).($mark !== '' ? ' '.$mark : '');
            }

            $lines[] = 'Total: '.static::money($d['focus_amount']);
        }

        return implode("\n", $lines);
    }
}
