<?php

namespace App\Services\IpoFeeds;

/** What one sync run actually did, for reporting back to the console or the panel. */
class IpoSyncResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $skipped = 0;

    public function total(): int
    {
        return $this->created + $this->updated + $this->unchanged + $this->skipped;
    }

    public function touchedAnything(): bool
    {
        return $this->created > 0 || $this->updated > 0;
    }

    public function summary(): string
    {
        if ($this->total() === 0) {
            return 'No IPOs came back from ipoalerts.in.';
        }

        return sprintf(
            '%d new, %d updated, %d already up to date.',
            $this->created,
            $this->updated,
            $this->unchanged,
        );
    }
}
