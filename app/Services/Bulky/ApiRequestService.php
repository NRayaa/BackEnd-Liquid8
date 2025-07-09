<?php

namespace App\Services\Bulky;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

class ApiRequestService
{
    protected static function baseRequest()
    {
        return Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-API-KEY' => config('bulky.api_token'),
        ])->baseUrl(config('bulky.base_url'));
    }

    protected static function request(string $method, string $url, array $params = [], array $headers = [])
    {
        try {
            $response = self::baseRequest()
                ->withHeaders($headers)
                ->send($method, $url, [
                    'query' => ($method === 'GET' ? $params : []),
                    'json' => ($method !== 'GET' ? $params : []),
                ])
                ->throw()
                ->json();

            return [
                'success' => true,
                'data' => $response['data'] ?? $response,
            ];
        } catch (RequestException $e) {
            return [
                'success' => false,
                'error' => $e->response?->json() ?? $e->getMessage(),
                'status' => $e->response?->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public static function get(string $url, array $queryParams = [], array $headers = [])
    {
        return self::request('GET', $url, $queryParams, $headers);
    }

    public static function post(string $url, array $formParams = [], array $headers = [])
    {
        return self::request('POST', $url, $formParams, $headers);
    }

    public static function put(string $url, array $formParams = [], array $headers = [])
    {
        return self::request('PUT', $url, $formParams, $headers);
    }

    public static function delete(string $url, array $headers = [])
    {
        return self::request('DELETE', $url, [], $headers);
    }
}
