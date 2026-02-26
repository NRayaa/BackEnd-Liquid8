<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Rack;
use Illuminate\Support\Str;

class RackSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $displayRacks = [
            'TOYS HOBBIES',
            'OTOMOTIF',
            'ELEKTRONIK',
            'ACC',
            'ACC GADGET',
            'HP, HV',
            'ART, KOMPOR KOPER',
            'F&B',
            'KOSMETIK, FMCG',
            'OBAT&SUPLEMEN',
            'ORGANIK, HEWAN, PESTISIDA',
            'SERVICE & SANITASI, HOME INDUSTRI',
            'ALAT KESEHATAN',
            'ATK',
            'TOOLS',
            'BABY PRODUCT',
            'FASHION',
            'REFURBISHED',
        ];

        foreach ($displayRacks as $name) {
            $randomString = strtoupper(Str::random(8));
            $barcodeValue = 'DIS-' . $randomString;
            Rack::firstOrCreate(
                [
                    'name' => $name,
                    'source' => 'display'
                ],
                [
                    'barcode' => $barcodeValue,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
