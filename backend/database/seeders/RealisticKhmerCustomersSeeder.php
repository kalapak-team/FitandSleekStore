<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 15 realistic Cambodia storefront customers (users + saved addresses).
 *
 * Run: php artisan db:seed --class=RealisticKhmerCustomersSeeder
 *
 * Default password for all: Customer@123
 */
class RealisticKhmerCustomersSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            [
                'name' => 'Norng Phuem',
                'email' => 'phuemnorng@gmail.com',
                'phone' => '0887525243',
                'days_ago' => 12,
                'address' => [
                    'label' => 'Home',
                    'house_no' => '42B',
                    'street_no' => '271',
                    'sangkat' => 'Tonle Bassac',
                    'khan' => 'Chamkar Mon',
                    'province' => 'Phnom Penh',
                    'landmark' => 'Near Aeon Mall Sen Sok',
                    'latitude' => 11.5568,
                    'longitude' => 104.8854,
                ],
            ],
            [
                'name' => 'Sophea Chan',
                'email' => 'sophea.chan.kh@gmail.com',
                'phone' => '0968123456',
                'days_ago' => 28,
                'address' => [
                    'label' => 'Home',
                    'house_no' => '18',
                    'street_no' => '63',
                    'sangkat' => 'Boeng Keng Kang 1',
                    'khan' => 'Boeng Keng Kang',
                    'province' => 'Phnom Penh',
                    'landmark' => 'Opposite Wat Langka',
                    'latitude' => 11.5332,
                    'longitude' => 104.9228,
                ],
            ],
            [
                'name' => 'Vanna Mao',
                'email' => 'vanna.mao88@yahoo.com',
                'phone' => '0977011223',
                'days_ago' => 45,
                'address' => [
                    'label' => 'Office',
                    'house_no' => '5',
                    'street_no' => '214',
                    'sangkat' => 'Phsar Thmei 1',
                    'khan' => 'Daun Penh',
                    'province' => 'Phnom Penh',
                    'landmark' => 'Near Central Market',
                    'latitude' => 11.5696,
                    'longitude' => 104.9210,
                ],
            ],
            [
                'name' => 'Kimhour Lim',
                'email' => 'kimhour.lim@gmail.com',
                'phone' => '0719338844',
                'days_ago' => 60,
                'address' => [
                    'label' => 'Home',
                    'house_no' => '120',
                    'street_no' => '598',
                    'sangkat' => 'Phnom Penh Thmey',
                    'khan' => 'Sen Sok',
                    'province' => 'Phnom Penh',
                    'landmark' => 'Behind PTT Sen Sok',
                    'latitude' => 11.6012,
                    'longitude' => 104.8719,
                ],
            ],
            [
                'name' => 'Bopha Ros',
                'email' => 'bopha.ros@outlook.com',
                'phone' => '0157788990',
                'days_ago' => 74,
                'address' => [
                    'label' => 'Home',
                    'house_no' => '7A',
                    'street_no' => '105',
                    'sangkat' => 'Svay Dangkum',
                    'khan' => 'Siem Reap',
                    'province' => 'Siem Reap',
                    'landmark' => 'Near Pub Street',
                    'latitude' => 13.3633,
                    'longitude' => 103.8564,
                ],
            ],
            [
                'name' => 'Dara Sok',
                'email' => 'dara.sok.kh@gmail.com',
                'phone' => '0892233445',
                'days_ago' => 90,
                'address' => [
                    'label' => 'Home',
                    'house_no' => '33',
                    'street_no' => '15',
                    'sangkat' => 'Svay Por',
                    'khan' => 'Battambang',
                    'province' => 'Battambang',
                    'landmark' => 'Near Psar Nath',
                    'latitude' => 13.0957,
                    'longitude' => 103.2022,
                ],
            ],
            [
                'name' => 'Srey Pich',
                'email' => 'sreypich.shop@gmail.com',
                'phone' => '0925566778',
                'days_ago' => 105,
                'address' => [
                    'label' => 'Home',
                    'house_no' => '9',
                    'street_no' => '360',
                    'sangkat' => 'Tuol Svay Prey 1',
                    'khan' => 'Boeng Keng Kang',
                    'province' => 'Phnom Penh',
                    'landmark' => 'Near Orussey Market',
                    'latitude' => 11.5449,
                    'longitude' => 104.9121,
                ],
            ],
            [
                'name' => 'Rithy Keo',
                'email' => 'rithy.keo@gmail.com',
                'phone' => '0701122334',
                'days_ago' => 130,
                'address' => [
                    'label' => 'Office',
                    'house_no' => '2F',
                    'street_no' => '182',
                    'sangkat' => 'Veal Vong',
                    'khan' => '7 Makara',
                    'province' => 'Phnom Penh',
                    'landmark' => 'Monivong Blvd',
                    'latitude' => 11.5583,
                    'longitude' => 104.9120,
                ],
            ],
            [
                'name' => 'Nary Phan',
                'email' => 'nary.phan.kh@gmail.com',
                'phone' => '0984455667',
                'days_ago' => 150,
                'address' => [
                    'label' => 'Home',
                    'house_no' => '56',
                    'street_no' => '217',
                    'sangkat' => 'Stung Meanchey 1',
                    'khan' => 'Meanchey',
                    'province' => 'Phnom Penh',
                    'landmark' => 'Near Stung Meanchey bridge',
                    'latitude' => 11.5289,
                    'longitude' => 104.9025,
                ],
            ],
            [
                'name' => 'Sokha Heng',
                'email' => 'sokha.heng@icloud.com',
                'phone' => '0863344556',
                'days_ago' => 168,
                'address' => [
                    'label' => 'Home',
                    'house_no' => '24',
                    'street_no' => '51',
                    'sangkat' => 'Buon',
                    'khan' => 'Mittakpheap',
                    'province' => 'Preah Sihanouk',
                    'landmark' => 'Near Independence Beach',
                    'latitude' => 10.6250,
                    'longitude' => 103.5234,
                ],
            ],
            [
                'name' => 'Chanthou Meas',
                'email' => 'chanthou.meas@gmail.com',
                'phone' => '0936677889',
                'days_ago' => 190,
                'address' => [
                    'label' => 'Home',
                    'house_no' => '11',
                    'street_no' => '440',
                    'sangkat' => 'Boeng Kak 1',
                    'khan' => 'Toul Kork',
                    'province' => 'Phnom Penh',
                    'landmark' => 'Near TK Avenue',
                    'latitude' => 11.5745,
                    'longitude' => 104.8988,
                ],
            ],
            [
                'name' => 'Pisach Ouk',
                'email' => 'pisach.ouk@gmail.com',
                'phone' => '0958899001',
                'days_ago' => 210,
                'address' => [
                    'label' => 'Home',
                    'house_no' => '3',
                    'street_no' => '8',
                    'sangkat' => 'Ta Khmau',
                    'khan' => 'Ta Khmau',
                    'province' => 'Kandal',
                    'landmark' => 'Near Kandal market',
                    'latitude' => 11.4533,
                    'longitude' => 104.9388,
                ],
            ],
            [
                'name' => 'Malis Chea',
                'email' => 'malis.chea.kh@gmail.com',
                'phone' => '0991011122',
                'days_ago' => 240,
                'address' => [
                    'label' => 'Home',
                    'house_no' => '67',
                    'street_no' => '310',
                    'sangkat' => 'Kakab 1',
                    'khan' => 'Pou Senchey',
                    'province' => 'Phnom Penh',
                    'landmark' => 'Near Camko City',
                    'latitude' => 11.5486,
                    'longitude' => 104.8381,
                ],
            ],
            [
                'name' => 'Visal Nhem',
                'email' => 'visal.nhem@gmail.com',
                'phone' => '0812233445',
                'days_ago' => 265,
                'address' => [
                    'label' => 'Office',
                    'house_no' => '14',
                    'street_no' => '96',
                    'sangkat' => 'Slor Kram',
                    'khan' => 'Siem Reap',
                    'province' => 'Siem Reap',
                    'landmark' => 'Airport road',
                    'latitude' => 13.3750,
                    'longitude' => 103.8320,
                ],
            ],
            [
                'name' => 'Leakhena Thy',
                'email' => 'leakhena.thy@gmail.com',
                'phone' => '0785566778',
                'days_ago' => 300,
                'address' => [
                    'label' => 'Home',
                    'house_no' => '21',
                    'street_no' => '2004',
                    'sangkat' => 'Chroy Changvar',
                    'khan' => 'Chroy Changvar',
                    'province' => 'Phnom Penh',
                    'landmark' => 'Koh Pich bridge side',
                    'latitude' => 11.5831,
                    'longitude' => 104.9312,
                ],
            ],
        ];

        foreach ($customers as $row) {
            $createdAt = now()->subDays($row['days_ago']);
            $addr = $row['address'];

            $user = User::updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'phone' => $row['phone'],
                    'password' => Hash::make('Customer@123'),
                    'role' => 'customer',
                    'status' => 'active',
                    'email_verified_at' => $createdAt->copy()->addHours(2),
                    'address' => json_encode([
                        'street' => $addr['street_no'],
                        'city' => $addr['province'],
                        'state' => $addr['khan'],
                        'zip' => '12000',
                        'country' => 'Cambodia',
                    ]),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]
            );

            Address::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'label' => $addr['label'],
                    'is_default' => true,
                ],
                [
                    'receiver_name' => $row['name'],
                    'receiver_phone' => $row['phone'],
                    'house_no' => $addr['house_no'],
                    'street_no' => $addr['street_no'],
                    'sangkat' => $addr['sangkat'],
                    'khan' => $addr['khan'],
                    'province' => $addr['province'],
                    'landmark' => $addr['landmark'],
                    'street' => 'Street '.$addr['street_no'],
                    'city' => $addr['province'],
                    'state' => $addr['khan'],
                    'zip' => '12000',
                    'country' => 'Cambodia',
                    'latitude' => $addr['latitude'],
                    'longitude' => $addr['longitude'],
                    'is_default' => true,
                ]
            );
        }

        $this->command?->info('Seeded '.count($customers).' realistic Khmer customers (password: Customer@123).');
    }
}
