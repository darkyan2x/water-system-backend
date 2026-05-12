<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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
                'name' => 'User',
                'email' => 'user@gmail.com',
                'password' => Hash::make('user'),
                'role' => 'user',
                'account_name' => 'User nga account',
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
