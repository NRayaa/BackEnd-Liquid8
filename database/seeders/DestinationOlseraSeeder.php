<?php

namespace Database\Seeders;

use App\Models\Destination;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DestinationOlseraSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $destinations = [
            [
                'shop_name' => 'Diskonter Proklamasi',
                'is_olsera_integrated' => true,
                'olsera_app_id' => 'IFRcKWdLuuB2Gk26q4l0',
                'olsera_secret_key' => 'ClHhvj03NRVYg8oln8T97b0OMy4NR5dX',
                'phone_number' => '08',
                'alamat' => 'Diskonter Proklamasi'
            ],
            [
                'shop_name' => 'Diskonter Pinang',
                'is_olsera_integrated' => true,
                'olsera_app_id' => 'YtqfBLJuDvku0eE45aBu',
                'olsera_secret_key' => 'X3uYTOgakrVtDosLMStNdtV4UjSZHXA9',
                'phone_number' => '08',
                'alamat' => 'Diskonter Pinang'
            ],
            [
                'shop_name' => 'Diskonter Cinere',
                'is_olsera_integrated' => true,
                'olsera_app_id' => 'amg8Zh4TnQfq8GxPJCoz',
                'olsera_secret_key' => 'Y4OTBpdEEbPcmzI4nqcHqjBe1tEi9cTT',
                'phone_number' => '08',
                'alamat' => 'Diskonter Cinere'
            ],
            [
                'shop_name' => 'Diskonter Kayu Manis',
                'is_olsera_integrated' => true,
                'olsera_app_id' => 'LTlexJCQvVblHP5p6d0X',
                'olsera_secret_key' => 'Yf5F6KVzGoEg3zcpVFmM62ROoLclc8P8',
                'phone_number' => '08',
                'alamat' => 'Diskonter Kayu Manis' 
            ],
            [
                'shop_name' => 'Diskonter Zambrud',
                'is_olsera_integrated' => true,
                'olsera_app_id' => 'CP3CsneLGEQg9WAfnslW',
                'olsera_secret_key' => 'I5Sx2Wg6B6zqCrcOjGSvMfWORWayYBJg',
                'phone_number' => '08',
                'alamat' => 'Diskonter Zambrud'
            ],
            [
                'shop_name' => 'Diskonter Bintaro',
                'is_olsera_integrated' => true,
                'olsera_app_id' => 'ZCIqFazJfFlk20bGib4a',
                'olsera_secret_key' => '33EwuVVrjS5bQ0yCpUomQBM8LAeheons',
                'phone_number' => '08',
                'alamat' => 'Diskonter Bintaro'
            ],
            [
                'shop_name' => 'Diskonter Pekayon',
                'is_olsera_integrated' => true,
                'olsera_app_id' => 'tA8qzA7aEynOhDTo3Avp',
                'olsera_secret_key' => 'efoGeHDTgytlJrABpVrTl3Ir1CqkpUi2',
                'phone_number' => '08',
                'alamat' => 'Diskonter Pekayon'
            ],
            [
                'shop_name' => 'Diskonter Harapan',
                'is_olsera_integrated' => true,
                'olsera_app_id' => 'mMiX1POMu82ucxoLpHmU',
                'olsera_secret_key' => 'bhKKotSdbfDqScvLsWHW2aoZkKog8rAW',
                'phone_number' => '08',
                'alamat' => 'Diskonter Harapan'
            ],
            [
                'shop_name' => 'Diskonter Loji',
                'is_olsera_integrated' => true,
                'olsera_app_id' => 'Aq3xc2bgkMCuXQvLg2Vf',
                'olsera_secret_key' => '7mU2C3ilNtAAG4Ftp21WFsasBlpvtflb',
                'phone_number' => '08',
                'alamat' => 'Diskonter Loji'
            ],
            [
                'shop_name' => 'Diskonter Mayor Oking',
                'is_olsera_integrated' => true,
                'olsera_app_id' => 'IdGByAN35lZvdoBsoAkn',
                'olsera_secret_key' => 'DLrPRTvX9W0tyEXjulDhVf18jXa40eIe',
                'phone_number' => '08',
                'alamat' => 'Diskonter Mayor Oking'
            ]
        ];

        foreach ($destinations as $data) {
            Destination::updateOrCreate(
                ['shop_name' => $data['shop_name']], 
                $data 
            );
        }
    }
}
