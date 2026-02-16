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
        $storeKayuManis     = Destination::where('shop_name', 'Diskonter Kayu Manis')->first();
        $storeZambrud     = Destination::where('shop_name', 'Diskonter Zambrud')->first();
        $storeBintaro     = Destination::where('shop_name', 'Diskonter Bintaro')->first();
        $storePekayon     = Destination::where('shop_name', 'Diskonter Pekayon')->first();
        $storeHarapan     = Destination::where('shop_name', 'Diskonter Harapan')->first();
        $storeLoji     = Destination::where('shop_name', 'Diskonter Loji')->first();
        $storeMayorOking     = Destination::where('shop_name', 'Diskonter Mayor Oking')->first();

        if (!$storeProklamasi) {
            $this->command->error("Data Destination 'Diskonter Proklamasi' atau 'Diskonter Cimone' belum ada di database! Harap input dulu.");
            return;
        }

        // proklamasi
        $mapProklamasi = [
            ['tag' => 'kuning', 'id_olsera' => '110294059', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '110294059', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '110294059', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '110294073', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '110294073', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '110294073', 'type' => 'sku_product'],
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
            ['tag' => 'kuning', 'id_olsera' => '110394817', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '110394817', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '110394817', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '110394824', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '110394824', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '110394824', 'type' => 'sku_product'],
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
            ['tag' => 'kuning', 'id_olsera' => '110395384', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '110395384', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '110395384', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '110395418', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '110395418', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '110395418', 'type' => 'sku_product'],
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
            ['tag' => 'kuning', 'id_olsera' => '110308283', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '110308283', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '110308283', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '110308275', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '110308275', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '110308275', 'type' => 'sku_product'],
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
            ['tag' => 'kuning', 'id_olsera' => '110395537', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '110395537', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '110395537', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '110395546', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '110395546', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '110395546', 'type' => 'sku_product'],
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
            ['tag' => 'kuning', 'id_olsera' => '110395577', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '110395577', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '110395577', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '110395581', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '110395581', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '110395581', 'type' => 'sku_product'],
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
            ['tag' => 'kuning', 'id_olsera' => '110395008', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '110395008', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '110395008', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '110395110', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '110395110', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '110395110', 'type' => 'sku_product'],
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
            ['tag' => 'kuning', 'id_olsera' => '110395221', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '110395221', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '110395221', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '110395251', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '110395251', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '110395251', 'type' => 'sku_product'],
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
            ['tag' => 'kuning', 'id_olsera' => '110395312', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '110395312', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '110395312', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '110395340', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '110395340', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '110395340', 'type' => 'sku_product'],
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
            ['tag' => 'kuning', 'id_olsera' => '110395413', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '110395413', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '110395413', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '110395458', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '110395458', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '110395458', 'type' => 'sku_product'],
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