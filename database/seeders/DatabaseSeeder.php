<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $users = [
            [
                'name' => 'Master Admin',
                'email' => 'master@gmail.com',
                'password' => Hash::make('master'),
                'role' => 'master',
                'account_name' => 'Master nga account',
            ],
            [
                'name' => 'Admin',
                'email' => 'admin@gmail.com',
                'password' => Hash::make('admin'),
                'role' => 'admin',
                'account_name' => 'Admin nga account',
            ],
            [
                'name' => 'Reader',
                'email' => 'reader@gmail.com',
                'password' => Hash::make('reader'),
                'role' => 'reader',
                'account_name' => 'Reader nga account',
            ],
            [
                'name' => 'Test User',
                'email' => 'user@gmail.com',
                'password' => Hash::make('user'),
                'role' => 'user',
                'account_name' => 'User nga account',
                'account_number' => '100001',
                'mobile' => '09694769690',
                'barangay' => 'Balayagmanok',
                'purok' => 'Purok 2',
                'status' => 'ok',
                'account_type' => 'residential',
            ],
            [
                'name' => 'Juan Dela Cruz',
                'email' => 'juan@gmail.com',
                'password' => Hash::make('juan'),
                'role' => 'user',
                'account_name' => 'Juan Dela Cruz',
                'account_number' => '100002',
                'mobile' => '09692169691',
                'barangay' => 'San Miguel',
                'purok' => 'Purok 1',
                'status' => 'delinquent',
                'account_type' => 'residential',
            ],
            [
                'name' => 'Toto Natividad',
                'email' => 'toto@gmail.com',
                'password' => Hash::make('toto'),
                'role' => 'user',
                'account_name' => 'Toto Natividad',
                'account_number' => '100003',
                'mobile' => '09497512236',
                'barangay' => 'San Miguel',
                'purok' => 'Purok 3',
                'status' => 'ok',
                'account_type' => 'residential',
            ],
            [
                'name' => 'J Truns',
                'email' => 'jtruns@gmail.com',
                'password' => Hash::make('jtruns'),
                'role' => 'user',
                'account_name' => 'Yero At Bakal Enterprises',
                'account_number' => '100004',
                'mobile' => '09496821236',
                'barangay' => 'San Miguel',
                'purok' => 'Purok 3',
                'status' => 'ok',
                'account_type' => 'commercial',
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']], // prevent duplicates
                $data
            );
        }
    }
}
