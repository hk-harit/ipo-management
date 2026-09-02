<?php

namespace App\Jobs;

use App\Models\IpoApplication;
use App\Services\AllotmentCheck\AllotmentCheckService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * One application's allotment check, run off the request/response cycle.
 *
 * Checking a registrar means an OCR captcha solve plus a couple of HTTP round
 * trips, each retried up to a few times (see BigshareChecker / MufgIntimeChecker)
 * — fine for a single application, but the "Check Allotment" bulk action can
 * select dozens at once. Running those one after another inside the web
 * request that handled the click risked a PHP timeout and left the admin
 * staring at a spinner the whole time. Queuing one job per application keeps
 * the panel responsive; AllotmentCheckBulkAction's batch `then()` callback
 * reports the summary once every job has run.
 */
class CheckAllotmentJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The registrar checkers already retry internally; a queue-level retry would just repeat a real failure. */
    public int $tries = 1;

    public function __construct(public readonly int $applicationId) {}

    public function handle(AllotmentCheckService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $application = IpoApplication::find($this->applicationId);

        if (! $application) {
            return;
        }

        $outcome = $service->checkForApplication($application);

        if ($batchId = $this->batch()?->id) {
            Cache::increment("allotment-check:{$batchId}:{$outcome->status}");
        }
    }
}
