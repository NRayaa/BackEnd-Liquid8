<?php

namespace App\Services;

use App\Models\Palet;
use App\Models\PaletSyncApprove;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class PaletSyncService
{
    protected $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.external.base_url');
    }

    public function syncPalet()
    {
        $response = Http::get($this->baseUrl . '/products');

        if (!$response->successful()) {
            return null;
        }

        $dataExternal = $response->json();
        $products = collect($dataExternal['data']);
        $externalIds = $products->pluck('wms_id')->toArray();

        $existingPaletIds = Palet::whereIn('id', $externalIds)->pluck('id')->toArray();

        $upsertData = collect($existingPaletIds)->map(function ($id) {
            return [
                'palet_id' => $id,
                'status' => 'sync',
            ];
        })->toArray();

        PaletSyncApprove::upsert(
            $upsertData,
            ['palet_id'],
            ['status']
        );

        return $existingPaletIds;
    }
    // public function syncPalet2()
    // {
    //     $response = Http::get($this->baseUrl . '/products');

    //     if (!$response->successful()) {
    //         return null;
    //     }

    //     // Filter hanya produk yang memiliki wms_id yang tidak null
    //     $filteredProducts = $response->filter(function ($product) {
    //         return !is_null($product['wms_id']);
    //     });

    //     // Jika tidak ada produk dengan wms_id yang valid, kembalikan null
    //     if ($filteredProducts->isEmpty()) {
    //         return null;
    //     }

    //     // Ambil wms_id dari produk yang sudah difilter
    //     $externalIds = $filteredProducts->pluck('wms_id')->toArray();


    //     return $externalIds;
    // }
}
