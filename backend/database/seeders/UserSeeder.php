<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create test users
        $users = [
            [
                'name' => 'Alice',
                'email' => 'alice@test.com',
                'password' => Hash::make('password'),
                'balance' => '100000.00000000',
                'locked_balance' => '0.00000000',
                'is_active' => true,
            ],
            [
                'name' => 'Bob',
                'email' => 'bob@test.com',
                'password' => Hash::make('password'),
                'balance' => '100000.00000000',
                'locked_balance' => '0.00000000',
                'is_active' => true,
            ],
        ];

        foreach ($users as $userData) {
            $user = User::updateOrCreate(
                ['email' => $userData['email']],
                $userData
            );

            // Create assets for each user
            $assets = [
                ['symbol' => 'BTC', 'amount' => '1.00000000', 'locked_amount' => '0.00000000'],
                ['symbol' => 'ETH', 'amount' => '10.00000000', 'locked_amount' => '0.00000000'],
            ];

            foreach ($assets as $assetData) {
                Asset::updateOrCreate(
                    ['user_id' => $user->id, 'symbol' => $assetData['symbol']],
                    $assetData
                );
            }
        }
    }
}
