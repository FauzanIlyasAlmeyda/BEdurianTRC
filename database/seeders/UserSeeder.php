<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\CollectorProfile;
use App\Models\ConsumerProfile;
use App\Models\DistributorProfile;
use App\Models\FarmerProfile;
use App\Models\UmkmProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

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
                'last_name' => 'Ilyas',
                'phone' => '081111111111',
                'email' => '123@gmail.com',
                'username' => 'petani',
                'password' => 'Fauzan123_',
                'role' => UserRole::Petani->value,
                'village' => 'Jati',
                'district' => 'Sidoarjo',
                'city' => 'Kabupaten Sidoarjo',
                'province' => 'Jawa Timur',
            ],

            [
                'first_name' => 'Misbah',
                'last_name' => 'Munir',
                'phone' => '082222222222',
                'email' => '321@gmail.com',
                'username' => 'pengepul',
                'password' => 'Fauzan123_',
                'role' => UserRole::Pengepul->value,
                'village' => 'Genjeng',
                'district' => 'Loceret',
                'city' => 'Kabupaten Nganjuk',
                'province' => 'Jawa Timur',
            ],

            [
                'first_name' => 'Miftahus',
                'last_name' => 'Haqqi',
                'phone' => '083333333333',
                'email' => '654@gmail.com',
                'username' => 'distributor',
                'password' => 'Fauzan123_',
                'role' => UserRole::Distributor->value,
                'village' => 'Pacitan',
                'district' => 'Pacitan',
                'city' => 'Kabupaten Pacitan',
                'province' => 'Jawa Timur',
            ],

            [
                'first_name' => 'Ahmad',
                'last_name' => 'Baihaqi',
                'phone' => '084444444444',
                'email' => '456@gmail.com',
                'username' => 'umkm',
                'password' => 'Fauzan123_',
                'role' => UserRole::Umkm->value,
                'village' => 'Plumpang',
                'district' => 'Plumpang',
                'city' => 'Kabupaten Tuban',
                'province' => 'Jawa Timur',
            ],

            [
                'first_name' => 'Adiyatma',
                'last_name' => 'Eka',
                'phone' => '085555555555',
                'email' => '789@gmail.com',
                'username' => 'konsumen',
                'password' => 'Fauzan123_',
                'role' => UserRole::Konsumen->value,
                'village' => 'Kebonagung',
                'district' => 'Mejayan',
                'city' => 'Kabupaten Madiun',
                'province' => 'Jawa Timur',
            ],

        ];

        foreach ($users as $user) {
            $account = User::updateOrCreate(
                ['email' => $user['email']],
                collect($user)->except(['village', 'district', 'city', 'province'])->all(),
            );

            $addressParts = [
                'village' => $user['village'],
                'district' => $user['district'],
                'city' => $user['city'],
                'province' => $user['province'],
            ];
            $address = implode(', ', array_values($addressParts));
            $fullName = $account->full_name;

            match ($account->role) {
                UserRole::Petani->value => FarmerProfile::updateOrCreate(
                    ['user_id' => $account->id],
                    $addressParts + [
                        'full_name' => $fullName,
                        'role_label' => 'Petani',
                        'location' => $address,
                        'contact' => $account->phone,
                    ],
                ),
                UserRole::Pengepul->value => CollectorProfile::updateOrCreate(
                    ['user_id' => $account->id],
                    $addressParts + [
                        'business_name' => $fullName,
                        'address' => $address,
                        'contact' => $account->phone,
                    ],
                ),
                UserRole::Distributor->value => DistributorProfile::updateOrCreate(
                    ['user_id' => $account->id],
                    $addressParts + [
                        'business_name' => $fullName,
                        'address' => $address,
                        'contact' => $account->phone,
                    ],
                ),
                UserRole::Umkm->value => UmkmProfile::updateOrCreate(
                    ['user_id' => $account->id],
                    $addressParts + [
                        'name' => $fullName,
                        'owner_name' => $fullName,
                        'address' => $address,
                        'contact' => $account->phone,
                    ],
                ),
                UserRole::Konsumen->value => ConsumerProfile::updateOrCreate(
                    ['user_id' => $account->id],
                    $addressParts + [
                        'display_name' => $fullName,
                        'address' => $address,
                        'phone' => $account->phone,
                    ],
                ),
            };
        }
    }
}
