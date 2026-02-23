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
        DB::table('olsera_product_mappings')->truncate();

        $storeProklamasi = Destination::where('shop_name', 'Diskonter Proklamasi')->first();
        $storePinang     = Destination::where('shop_name', 'Diskonter Pinang')->first();
        $storeCinere     = Destination::where('shop_name', 'Diskonter Cinere')->first();
        $storeKayuManis  = Destination::where('shop_name', 'Diskonter Kayu Manis')->first();
        $storeZambrud    = Destination::where('shop_name', 'Diskonter Zambrud')->first();
        $storeBintaro    = Destination::where('shop_name', 'Diskonter Bintaro')->first();
        $storePekayon    = Destination::where('shop_name', 'Diskonter Pekayon')->first();
        $storeHarapan    = Destination::where('shop_name', 'Diskonter Harapan')->first();
        $storeLoji       = Destination::where('shop_name', 'Diskonter Loji')->first();
        $storeMayorOking = Destination::where('shop_name', 'Diskonter Mayor Oking')->first();

        if (!$storeProklamasi) {
            $this->command->error("Data Destination 'Diskonter Proklamasi' atau 'Diskonter Cimone' belum ada di database! Harap input dulu.");
            return;
        }

        // proklamasi
        $mapProklamasi = [
            ['tag' => 'kuning', 'id_olsera' => '89356978', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '89356978', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '89356978', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '89356979', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '89356979', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '89356979', 'type' => 'sku_product'],
        ];

        foreach ($mapProklamasi as $item) {
            OlseraProductMapping::updateOrCreate([
                'destination_id' => $storeProklamasi->id,
                'wms_identifier' => $item['tag'],
                'olsera_id'      => $item['id_olsera'],
                'type'           => $item['type']
            ]);
        }

        // pinang
        $mapPinang = [
            ['tag' => 'kuning', 'id_olsera' => '85925026', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '85925026', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '85925026', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '85925027', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '85925027', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '85925027', 'type' => 'sku_product'],
        ];

        foreach ($mapPinang as $item) {
            OlseraProductMapping::updateOrCreate([
                'destination_id' => $storePinang->id,
                'wms_identifier' => $item['tag'],
                'olsera_id'      => $item['id_olsera'],
                'type'           => $item['type']
            ]);
        }

        // cinere
        $mapCinere = [
            ['tag' => 'kuning', 'id_olsera' => '83372506', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '83372506', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '83372506', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '83372507', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '83372507', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '83372507', 'type' => 'sku_product'],
        ];

        foreach ($mapCinere as $item) {
            OlseraProductMapping::updateOrCreate([
                'destination_id' => $storeCinere->id,
                'wms_identifier' => $item['tag'],
                'olsera_id'      => $item['id_olsera'],
                'type'           => $item['type']
            ]);
        }

        // kayu manis
        $mapKayuManis = [
            ['tag' => 'kuning', 'id_olsera' => '82547328', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '82547328', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '82547328', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '82547348', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '82547348', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '82547348', 'type' => 'sku_product'],
        ];

        foreach ($mapKayuManis as $item) {
            OlseraProductMapping::updateOrCreate([
                'destination_id' => $storeKayuManis->id,
                'wms_identifier' => $item['tag'],
                'olsera_id'      => $item['id_olsera'],
                'type'           => $item['type']
            ]);
        }

        // zambrud
        $mapZambrud = [
            ['tag' => 'kuning', 'id_olsera' => '80487828', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '80487828', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '80487828', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '80487827', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '80487827', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '80487827', 'type' => 'sku_product'],
        ];

        foreach ($mapZambrud as $item) {
            OlseraProductMapping::updateOrCreate([
                'destination_id' => $storeZambrud->id,
                'wms_identifier' => $item['tag'],
                'olsera_id'      => $item['id_olsera'],
                'type'           => $item['type']
            ]);
        }

        // bintaro
        $mapBintaro = [
            ['tag' => 'kuning', 'id_olsera' => '78837902', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '78837902', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '78837902', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '78837901', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '78837901', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '78837901', 'type' => 'sku_product'],
        ];

        foreach ($mapBintaro as $item) {
            OlseraProductMapping::updateOrCreate([
                'destination_id' => $storeBintaro->id,
                'wms_identifier' => $item['tag'],
                'olsera_id'      => $item['id_olsera'],
                'type'           => $item['type']
            ]);
        }

        // pekayon
        $mapPekayon = [
            ['tag' => 'kuning', 'id_olsera' => '78146542', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '78146542', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '78146542', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '78146541', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '78146541', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '78146541', 'type' => 'sku_product'],
        ];

        foreach ($mapPekayon as $item) {
            OlseraProductMapping::updateOrCreate([
                'destination_id' => $storePekayon->id,
                'wms_identifier' => $item['tag'],
                'olsera_id'      => $item['id_olsera'],
                'type'           => $item['type']
            ]);
        }

        // harapan
        $mapHarapan = [
            ['tag' => 'kuning', 'id_olsera' => '77618277', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '77618277', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '77618277', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '77618278', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '77618278', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '77618278', 'type' => 'sku_product'],
        ];

        foreach ($mapHarapan as $item) {
            OlseraProductMapping::updateOrCreate([
                'destination_id' => $storeHarapan->id,
                'wms_identifier' => $item['tag'],
                'olsera_id'      => $item['id_olsera'],
                'type'           => $item['type']
            ]);
        }

        // loji
        $mapLoji = [
            ['tag' => 'kuning', 'id_olsera' => '76661552', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '76661552', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '76661552', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '76661551', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '76661551', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '76661551', 'type' => 'sku_product'],
        ];

        foreach ($mapLoji as $item) {
            OlseraProductMapping::updateOrCreate([
                'destination_id' => $storeLoji->id,
                'wms_identifier' => $item['tag'],
                'olsera_id'      => $item['id_olsera'],
                'type'           => $item['type']
            ]);
        }

        // mayor oking
        $mapMayorOking = [
            ['tag' => 'kuning', 'id_olsera' => '70286445', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '70286445', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '70286445', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '70286562', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '70286562', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '70286562', 'type' => 'sku_product'],
        ];

        foreach ($mapMayorOking as $item) {
            OlseraProductMapping::updateOrCreate([
                'destination_id' => $storeMayorOking->id,
                'wms_identifier' => $item['tag'],
                'olsera_id'      => $item['id_olsera'],
                'type'           => $item['type']
            ]);
        }
    }
}
