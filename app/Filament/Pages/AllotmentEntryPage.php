<?php

namespace App\Filament\Pages;

use App\Models\Ipo;
use App\Models\IpoApplication;
use App\Services\AccountOverview;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Record an IPO's allotment result in one go, for registrars the app cannot look up by
 * itself (KFin Technologies, for one). Check the results on the registrar / NSE / BSE site,
 * paste the allotted PANs, mark the rest as not allotted, save.
 *
 * Saving goes through the normal model, so balances, refunds, the passbook, the dashboard
 * and the activity log all follow, exactly as if each application had been edited by hand.
 */
class AllotmentEntryPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'IPO Management';

    protected static ?string $navigationLabel = 'Allotment Entry (એલોટમેન્ટ)';

    protected static ?string $title = 'Allotment Entry (એલોટમેન્ટ)';

    protected static ?string $slug = 'allotment-entry';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.allotment-entry';

    public string $ipo = '';

    /** application id => ['result' => pending|allotted|refunded, 'shares' => int|null] */
    public array $rows = [];

    public string $paste = '';

    public function mount(): void
    {
        $this->ipo = (string) ((new AccountOverview)->ipoOptions()->first()['id'] ?? '');
        $this->loadRows();
    }

    public function updatedIpo(): void
    {
        $this->paste = '';
        $this->loadRows();
    }

    public function getSubheading(): ?string
    {
        return 'Registrar / NSE / BSE par result joi ne ahiya ek sathe nakho. Allotted PAN paste karo, baki "Not allotted".';
    }

    public function ipoOptions(): Collection
    {
        return (new AccountOverview)->ipoOptions();
    }

    public function selectedIpo(): ?Ipo
    {
        return $this->ipo === '' ? null : Ipo::find((int) $this->ipo);
    }

    /** The applications whose result can still be entered or corrected (not yet sold). */
    public function applications(): Collection
    {
        if ($this->ipo === '') {
            return collect();
        }

        return IpoApplication::query()
            ->with(['applicant:id,name,pan_number', 'account:id,name'])
            ->where('ipo_id', (int) $this->ipo)
            ->whereIn('status', ['applied', 'allotted', 'refunded'])
            ->orderBy('applicant_id')
            ->orderBy('id')
            ->get();
    }

    /** Whole lots this application is for, from its amount. */
    public function lotsFor(IpoApplication $application): ?int
    {
        $lotAmount = $this->selectedIpo()?->lot_amount;

        return $lotAmount ? max(1, (int) round((float) $application->amount / $lotAmount)) : null;
    }

    /** Shares to pre-fill when an application is marked allotted: one lot's worth. */
    private function defaultShares(): ?int
    {
        return $this->selectedIpo()?->lot_size ?: null;
    }

    private function loadRows(): void
    {
        $this->rows = $this->applications()->mapWithKeys(fn (IpoApplication $a) => [
            $a->id => [
                'result' => match ($a->status) {
                    'allotted' => 'allotted',
                    'refunded' => 'refunded',
                    default => 'pending',
                },
                'shares' => $a->status === 'allotted' ? (int) $a->shares_allotted : null,
            ],
        ])->all();
    }

    /** Marks every application whose PAN appears in the pasted text as allotted. */
    public function applyPaste(): void
    {
        preg_match_all('/\b[A-Z]{5}[0-9]{4}[A-Z]\b/i', strtoupper($this->paste), $matches);
        $pans = array_unique($matches[0]);

        if ($pans === []) {
            Notification::make()->warning()->title('Koi PAN malyo nahi')->body('PAN (jem ke ABCDE1234F) ek line ma ek, paste karo.')->send();

            return;
        }

        $found = [];
        foreach ($this->applications() as $application) {
            $pan = strtoupper(trim((string) $application->applicant?->pan_number));

            if ($pan !== '' && in_array($pan, $pans, true)) {
                $this->rows[$application->id]['result'] = 'allotted';
                $this->rows[$application->id]['shares'] ??= $this->defaultShares();
                $found[$pan] = true;
            }
        }

        $unknown = array_values(array_diff($pans, array_keys($found)));

        Notification::make()
            ->success()
            ->title(count($found).' PAN allotted marks thaya')
            ->body($unknown === [] ? 'Badha PAN ni application mali.' : 'Aa PAN ni koi application nathi: '.implode(', ', $unknown))
            ->persistent($unknown !== [])
            ->send();
    }

    /** Everything still waiting gets "not allotted". */
    public function markRestRefunded(): void
    {
        foreach ($this->rows as $id => $row) {
            if ($row['result'] === 'pending') {
                $this->rows[$id]['result'] = 'refunded';
            }
        }
    }

    public function save(): void
    {
        $applications = $this->applications()->keyBy('id');
        $changed = ['allotted' => 0, 'refunded' => 0, 'pending' => 0];
        $problems = [];

        foreach ($this->rows as $id => $row) {
            $application = $applications->get($id);
            if (! $application || $row['result'] === $this->currentResult($application)) {
                continue;
            }

            if ($row['result'] === 'allotted' && (int) ($row['shares'] ?? 0) <= 0) {
                $problems[] = $application->applicant?->name.': shares allotted lakho';
            }
        }

        if ($problems !== []) {
            Notification::make()->danger()->title('Pehla shares bharo')->body(implode("\n", array_slice($problems, 0, 6)))->persistent()->send();

            return;
        }

        DB::transaction(function () use ($applications, &$changed) {
            foreach ($this->rows as $id => $row) {
                $application = $applications->get($id);
                if (! $application || $row['result'] === $this->currentResult($application)) {
                    continue;
                }

                match ($row['result']) {
                    'allotted' => $application->update([
                        'status' => 'allotted',
                        'shares_allotted' => (int) $row['shares'],
                        'return_amount' => null,
                        'return_date' => null,
                    ]),
                    // The model fills the full refund amount and date for a refunded application.
                    'refunded' => $application->update([
                        'status' => 'refunded',
                        'shares_allotted' => null,
                        'return_amount' => null,
                        'return_date' => null,
                    ]),
                    default => $application->update([
                        'status' => 'applied',
                        'shares_allotted' => null,
                        'return_amount' => null,
                        'return_date' => null,
                    ]),
                };

                $changed[$row['result']]++;
            }
        });

        $this->loadRows();

        Notification::make()
            ->success()
            ->title('Allotment save thayu')
            ->body("{$changed['allotted']} allotted · {$changed['refunded']} not allotted (refund) · {$changed['pending']} pachha pending")
            ->send();
    }

    private function currentResult(IpoApplication $application): string
    {
        return match ($application->status) {
            'allotted' => 'allotted',
            'refunded' => 'refunded',
            default => 'pending',
        };
    }

    /** Counts for the summary strip, from the unsaved choices on screen. */
    public function tally(): array
    {
        $counts = collect($this->rows)->countBy('result');

        return [
            'allotted' => (int) $counts->get('allotted', 0),
            'refunded' => (int) $counts->get('refunded', 0),
            'pending' => (int) $counts->get('pending', 0),
            'total' => count($this->rows),
        ];
    }
}
