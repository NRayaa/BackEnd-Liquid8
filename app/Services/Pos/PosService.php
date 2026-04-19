<?php

namespace App\Services\Pos;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PosService
{
    protected $baseUrl;
    protected $clientId;
    protected $clientSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('POS_API_URL'), '/');
        $this->clientId = env('POS_CLIENT_ID');
        $this->clientSecret = env('POS_CLIENT_SECRET');
    }

    /**
     * 1. Get OAuth Token
     */
    public function getToken()
    {
        return Cache::remember('pos_oauth_token', 3300, function () {

            $response = Http::post($this->baseUrl . '/api/oauth/token', [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            $data = $response->json();

            if ($response->successful() && !empty($data['access_token'])) {
                return $data['access_token'];
            }

            Log::error('POS Token Error: ' . $response->body());
            throw new \Exception('Gagal Get Token POS. Response Server: ' . $response->body());
        });
    }

    /**
     * 2. Get List Stores / Destination Tokens
     */
    public function getStores()
    {
        $token = $this->getToken();

        $response = Http::withToken($token)->get($this->baseUrl . '/api/destination-stores/sync');

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Gagal mengambil list store dari POS: ' . $response->body());
        throw new \Exception('Gagal mengambil data toko dari server POS.');
    }

    /**
     * 3. Send Batch Products to POS
     */
    public function sendBatchProducts($documentCode, $storeToken, $products)
    {
        $token = $this->getToken();

        $payload = [
            "document_code" => $documentCode,
            "store_token"   => $storeToken,
            "products"      => $products
        ];

        $response = Http::withToken($token)
            ->acceptJson() 
            ->post($this->baseUrl . '/api/products/store', $payload);

        if ($response->successful()) {
            return $response->json();
        }

        if ($response->status() === 401) {
            Cache::forget('pos_oauth_token'); 
            
            Log::warning("Token POS Expired/Invalid (401), cache telah dibersihkan secara otomatis.");
            
            throw new \Exception("Token otorisasi POS telah diperbarui secara otomatis. Silakan klik tombol 'Selesaikan Migrasi' sekali lagi.");
        }

        Log::error("Gagal mengirim batch Dokumen {$documentCode}: " . $response->body());
        throw new \Exception("Gagal mengirim batch produk ke POS. Status: " . $response->status() . " | Pesan: " . $response->body());
    }
}
