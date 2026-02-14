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
        $storeKayuManis     = Destination::where('shop_name', 'Diskonter Kayu Manis')->first();

        if (!$storeProklamasi) {
            $this->command->error("Data Destination 'Diskonter Proklamasi' atau 'Diskonter Cimone' belum ada di database! Harap input dulu.");
            return;
        }

        $mapProklamasi = [
            ['tag' => 'kuning', 'id_olsera' => '110294059', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '110294059', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '110294059', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '110294073', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '110294073', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '110294073', 'type' => 'sku_product'],
        ];

        foreach ($mapProklamasi as $item) {
            OlseraProductMapping::create([
                'destination_id' => $storeProklamasi->id,
                'wms_identifier' => $item['tag'],
                'olsera_id'      => $item['id_olsera'],
                'type'           => $item['type']
            ]);
        }

        $mapKayuManis = [
            ['tag' => 'kuning', 'id_olsera' => '110308283', 'type' => 'color_tag'],
            ['tag' => 'hijau',  'id_olsera' => '110308283', 'type' => 'color_tag'],
            ['tag' => 'small',  'id_olsera' => '110308283', 'type' => 'sku_product'],

            ['tag' => 'merah',  'id_olsera' => '110308275', 'type' => 'color_tag'],
            ['tag' => 'biru',   'id_olsera' => '110308275', 'type' => 'color_tag'],
            ['tag' => 'big',    'id_olsera' => '110308275', 'type' => 'sku_product'],
        ];

        foreach ($mapKayuManis as $item) {
            OlseraProductMapping::create([
                'destination_id' => $storeKayuManis->id,
                'wms_identifier' => $item['tag'],
                'olsera_id'      => $item['id_olsera'],
                'type'           => $item['type']
            ]);
        }
    }
}