<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with starter data for the first
     * (primary) login. Every other login starts with a clean slate and
     * adds their own Sources/Accounts through the panel.
     */
    public function run(): void
    {
        $owner = User::orderBy('id')->first();

        if (! $owner) {
            return;
        }

        collect([
            ['name' => 'Uncle A', 'relation' => 'Uncle'],
            ['name' => 'Brother B', 'relation' => 'Brother'],
            ['name' => 'Brother C', 'relation' => 'Brother'],
        ])->each(fn (array $source) => Source::firstOrCreate(
            ['user_id' => $owner->id, 'name' => $source['name']],
            $source
        ));

        collect([
            ['name' => 'My Bank', 'holder_name' => 'Self', 'type' => 'bank'],
            ['name' => 'My Cash', 'holder_name' => 'Self', 'type' => 'cash'],
            ['name' => "Mummy's Bank", 'holder_name' => 'Mummy', 'type' => 'bank'],
            ['name' => "Papa's Bank", 'holder_name' => 'Papa', 'type' => 'bank'],
            ['name' => "Nirbhay's Bank", 'holder_name' => 'Nirbhay', 'type' => 'bank'],
        ])->each(fn (array $account) => Account::firstOrCreate(
            ['user_id' => $owner->id, 'name' => $account['name']],
            $account
        ));
    }
}
