<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'milanstankovic19939@gmail.com'],
            [
                'name' => 'Milan Stankovic',
                'password' => Hash::make('28januar'),
            ]
        );
    }
}
