<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\IpoFeeds\FeedException;
use App\Services\IpoFeeds\IpoAlertsFeed;
use App\Services\IpoFeeds\IpoSync;
use Illuminate\Console\Command;

class SyncIpos extends Command
{
    protected $signature = 'ipos:sync
                            {--user= : Only sync this user id (default: every user)}';

    protected $description = 'Pull the IPO listing from ipoalerts.in and chittorgarh.com into each user\'s IPOs';

    public function handle(): int
    {
        if (! IpoAlertsFeed::fromConfig()->hasApiKey()) {
            $this->warn('No IPOALERTS_API_KEY set — ipoalerts.in will return only one IPO per status.');
            $this->line('Get a key at https://ipoalerts.in. chittorgarh.com needs no key and runs regardless.');
            $this->newLine();
        }

        $sync = IpoSync::fromConfig();

        try {
            $rows = $sync->gather();
        } catch (FeedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($sync->failures() as $label => $reason) {
            $this->warn($label.' failed, carrying on without it: '.$reason);
        }

        $this->info(count($rows).' IPOs came back across the feeds.');

        $users = $this->option('user')
            ? User::where('id', $this->option('user'))->get()
            : User::orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->error('No matching users to sync into.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $result = $sync->apply($user, $rows);

            $this->line(sprintf('  %-30s %s', $user->name, $result->summary()));
        }

        return self::SUCCESS;
    }
}
