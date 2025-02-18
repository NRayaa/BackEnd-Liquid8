<?php

use Illuminate\Support\Facades\Http;

function jurnalRequest($method, $url, $body = [], $extraHeaders = [])
{
    $hmacUsername = 'yAopN2tBvn2iZK8d';
    $hmacSecret = 'MTahg1CZXrhGvRHbDZkY1hr5osn5TtNY';

    // Debugging
    // $hmacUsername = 'AILLTotWHI8RfZ0g';
    // $hmacSecret = 'kQrU85vnYbfNTBsJYDSlIREDMVHT45Ek';

    $dateString = gmdate('D, d M Y H:i:s T');

    $method = strtoupper($method);

    // Parsing URL untuk mendapatkan path dan query string
    $parsedUrl = parse_url($url);
    $requestPath = $parsedUrl['path'] . (isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '');
    $requestLine = "{$method} {$requestPath} HTTP/1.1";

    // Membuat string untuk ditandatangani
    $stringToSign = "date: {$dateString}\n{$requestLine}";

    // Generate HMAC Signature
    $signature = base64_encode(hash_hmac('sha256', $stringToSign, $hmacSecret, true));

    // Membuat HMAC Header
    $hmacHeader = "hmac username=\"{$hmacUsername}\", algorithm=\"hmac-sha256\", headers=\"date request-line\", signature=\"{$signature}\"";

    // Menyusun header
    $headers = array_merge([
        'Authorization' => $hmacHeader,
        'Date' => $dateString,
        'Content-Type' => 'application/json',
    ], $extraHeaders);

    // Mengirim request HTTP sesuai method yang dipilih
    $request = Http::timeout(60)->withHeaders($headers);

    switch ($method) {
        case 'POST':
            return $request->post($url, $body);
        case 'PUT':
            return $request->put($url, $body);
        case 'DELETE':
            return $request->delete($url);
        default: // GET sebagai default
            return $request->get($url);
    }
}
