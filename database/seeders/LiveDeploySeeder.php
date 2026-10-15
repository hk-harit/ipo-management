<?php

namespace Database\Seeders;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class LiveDeploySeeder extends Seeder
{
    /**
     * Seeds the Admin users and all 56 saved Applicants for production deployment.
     */
    public function run(): void
    {
        $dataFile = __DIR__ . '/applicants_data.json';

        if (! File::exists($dataFile)) {
            $this->command->error("Data file {$dataFile} not found.");
            return;
        }

        $data = json_decode(File::get($dataFile), true);

        // Seed Users
        foreach ($data['users'] as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => $userData['password'],
                ]
            );
        }

        $this->command->info(count($data['users']) . ' users synced.');

        // Seed Applicants
        $admin = User::first();
        if (! $admin) {
            $this->command->error('No admin user found to attach applicants.');
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        foreach ($data['applicants'] as $applicantData) {
            Applicant::updateOrCreate(
                [
                    'pan_number' => $applicantData['pan_number'],
                    'user_id' => $applicantData['user_id'] ?? $admin->id,
                ],
                [
                    'name' => $applicantData['name'],
                    'relation' => $applicantData['relation'] ?? 'other',
                    'demat_account' => $applicantData['demat_account'] ?? null,
                    'notes' => $applicantData['notes'] ?? null,
                    'is_active' => $applicantData['is_active'] ?? true,
                ]
            );
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->command->info(count($data['applicants']) . ' applicants synced successfully.');
    }
}
