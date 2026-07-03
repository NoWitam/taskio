<?php

namespace Database\Seeders;

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
        $n = 9;

        User::create([
            'name' => 'Hubert Golewski',
            'email' => 'wowek31@gmail.com',
            'email_verified_at' => now(),
            'password' => Hash::make('test'),
        ]);

        for ($i = 0; $i < $n; $i++) {
            User::create([
                'name' => fake()->name(),
                'email' => fake()->email(),
                'email_verified_at' => now(),
                'password' => Hash::make('test'),
            ]);
        }
    }
}
