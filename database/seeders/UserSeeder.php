<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [

            [
                'first_name' => 'Fauzan',
                'last_name' => 'llyas',
                'phone' => '081111111111',
                'email' => '123@gmail.com',
                'username' => 'petani',
                'password' => 'Fauzan123_',
                'role' => UserRole::Petani->value,
            ],

            [
                'first_name' => 'Misbah',
                'last_name' => 'Munir',
                'phone' => '082222222222',
                'email' => '321@gmail.com',
                'username' => 'pengepul',
                'password' => 'Fauzan123_',
                'role' => UserRole::Pengepul->value,
            ],

            [
                'first_name' => 'Miftahus',
                'last_name' => 'Haqqi',
                'phone' => '083333333333',
                'email' => '654@gmail.com',
                'username' => 'distributor',
                'password' => 'Fauzan123_',
                'role' => UserRole::Distributor->value,
            ],

            [
                'first_name' => 'Ahmad',
                'last_name' => 'Baihaqi',
                'phone' => '084444444444',
                'email' => '456@gmail.com',
                'username' => 'umkm',
                'password' => 'Fauzan123_',
                'role' => UserRole::Umkm->value,
            ],

            [
                'first_name' => 'Adiyatma',
                'last_name' => 'Eka',
                'phone' => '085555555555',
                'email' => '789@gmail.com',
                'username' => 'konsumen',
                'password' => 'Fauzan123_',
                'role' => UserRole::Konsumen->value,
            ]

        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                $user
            );
        }
    }
}
