<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\OlseraProductMapping;
use App\Models\Destination;
use Illuminate\Support\Facades\DB;

class OlseraMappingSeeder extends Seeder
{
    public function run(): void
    {
        // DB::table('olsera_product_mappings')->truncate();

        $storeProklamasi = Destination::where('shop_name', 'Diskonter Proklamasi')->first();
        if (!$storeProklamasi) {
            $this->command->error("Data Destination 'Diskonter Proklamasi' atau 'Diskonter Cimone' belum ada di database! Harap input dulu.");
            return;
        }

        $storesConfig = [
            'Diskonter Proklamasi'  => ['89356978', '89356979'],
            'Diskonter Pinang'      => ['85925026', '85925027'],
            'Diskonter Cinere'      => ['83372506', '83372507'],
            'Diskonter Kayu Manis'  => ['82547328', '82547348'],
            'Diskonter Zambrud'     => ['80487828', '80487827'],
            'Diskonter Bintaro'     => ['78837902', '78837901'],
            'Diskonter Pekayon'     => ['78146542', '78146541'],
            'Diskonter Harapan'     => ['77618277', '77618278'],
            'Diskonter Loji'        => ['76661552', '76661551'],
            'Diskonter Mayor Oking' => ['70286445', '70286562'],
        ];

        foreach ($storesConfig as $shopName => $ids) {
            $store = Destination::where('shop_name', $shopName)->first();

            if ($store) {
                $mappings = [
                    // Kuning, Putih, Hijau, Small
                    ['tag' => 'kuning', 'id_olsera' => $ids[0], 'type' => 'color_tag'],
                    ['tag' => 'putih',  'id_olsera' => $ids[0], 'type' => 'color_tag'],
                    ['tag' => 'hijau',  'id_olsera' => $ids[0], 'type' => 'color_tag'],
                    ['tag' => 'small',  'id_olsera' => $ids[0], 'type' => 'sku_product'],

                    // Merah, Oranye, Biru, Big
                    ['tag' => 'merah',  'id_olsera' => $ids[1], 'type' => 'color_tag'],
                    ['tag' => 'oranye', 'id_olsera' => $ids[1], 'type' => 'color_tag'],
                    ['tag' => 'biru',   'id_olsera' => $ids[1], 'type' => 'color_tag'],
                    ['tag' => 'big',    'id_olsera' => $ids[1], 'type' => 'sku_product'],
                ];

                foreach ($mappings as $item) {
                    OlseraProductMapping::updateOrCreate(
                        [
                            'destination_id' => $store->id,
                            'wms_identifier' => $item['tag'],
                        ],
                        [
                            'olsera_id'      => $item['id_olsera'],
                            'type'           => $item['type']
                        ]
                    );
                }
            }
        }
    }
}
