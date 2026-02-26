<?php

namespace App\Services\Olsera;

use App\Models\Destination;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OlseraService
{
    protected $baseUrl;
    protected $destination;

    public function __construct(Destination $destination)
    {
        $this->baseUrl = rtrim(config('services.olsera.base_url', 'https://api-open.olsera.co.id/api/open-api/v1'), '/');
        $this->destination = $destination;
    }

    private function getToken()
    {
        if ($this->destination->olsera_access_token && !$this->destination->isTokenExpired()) {
            return $this->destination->olsera_access_token;
        }

        if ($this->destination->olsera_refresh_token) {
            return $this->refreshToken();
        }

        return $this->requestNewToken();
    }

    public function requestNewToken()
    {
        return $this->performAuthRequest([
            'grant_type' => 'secret_key',
            'app_id' => $this->destination->olsera_app_id,
            'secret_key' => $this->destination->olsera_secret_key,
        ], 'INITIAL_AUTH');
    }

    private function refreshToken()
    {
        $token = $this->performAuthRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->destination->olsera_refresh_token,
        ], 'REFRESH_AUTH');

        if (!$token) {
            return $this->requestNewToken();
        }
        return $token;
    }

    private function performAuthRequest($params, $logTag)
    {
        try {
            $response = Http::asForm()->acceptJson()->post($this->baseUrl . '/id/token', $params);
            $data = $response->json();

            if (isset($data['access_token'])) {
                $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] - 60 : 86000;

                $this->destination->update([
                    'olsera_access_token' => $data['access_token'],
                    'olsera_refresh_token' => $data['refresh_token'] ?? $this->destination->olsera_refresh_token,
                    'olsera_token_expires_at' => now()->addSeconds($expiresIn)
                ]);

                return $data['access_token'];
            }

            $errorMsg = $data['message'] ?? $data['error'] ?? 'Unknown Error dari Olsera';
            Log::error("[OLSERA-{$logTag}] Failed for {$this->destination->shop_name}", $data);

            throw new \Exception(is_array($errorMsg) ? json_encode($errorMsg) : $errorMsg);
        } catch (\Exception $e) {
            Log::error("[OLSERA-{$logTag}] Connection Error: " . $e->getMessage());
            throw new \Exception($e->getMessage());
        }
    }

    private function sendRequest($method, $endpoint, $params = [])
    {
        $token = $this->getToken();

        if (!$token) {
            return [
                'success' => false,
                'message' => 'Gagal autentikasi ke Olsera. Cek App ID/Secret Key di data Destination.',
                'status_code' => 401
            ];
        }

        $url = $this->baseUrl . '/en/' . $endpoint;

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->$method($url, $params);

            Log::channel('daily')->info("[OLSERA] {$method} {$endpoint}", [
                'store' => $this->destination->shop_name,
                'status' => $response->status()
            ]);

            return $this->mapResponse($response);
        } catch (\Exception $e) {
            Log::error("[OLSERA-HTTP-ERR] " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System Error: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    private function mapResponse($response)
    {
        $body = $response->json();

        if ($response->successful()) {
            if (isset($body['error']) && !empty($body['error'])) {
                return [
                    'success' => false,
                    'data' => null,
                    'message' => is_array($body['error']) ? json_encode($body['error']) : $body['error'],
                    'status_code' => 400
                ];
            }

            return [
                'success' => true,
                'data' => $body,
                'message' => 'Success',
                'status_code' => $response->status()
            ];
        }

        return [
            'success' => false,
            'message' => $body['message'] ?? $body['error']['message'] ?? 'Unknown Error',
            'data' => $body,
            'status_code' => $response->status()
        ];
    }


    public function createStockInOut($data)
    {
        return $this->sendRequest('post', 'inventory/stockinout', $data);
    }

    public function addItemStockInOut($data)
    {
        return $this->sendRequest('post', 'inventory/stockinout/additem', $data);
    }

    public function updateStatusStockInOut($data)
    {
        return $this->sendRequest('post', 'inventory/stockinout/updatestatus', $data);
    }

    public function getStockInOutDetail($params)
    {
        return $this->sendRequest('get', 'inventory/stockinout/detail', $params);
    }

    public function getOutgoingStockList($params = [])
    {
        return $this->sendRequest('get', 'inventory/stockoutgoing', $params);
    }

    public function getProductList($params = [])
    {
        return $this->sendRequest('get', 'product', $params);
    }
}
