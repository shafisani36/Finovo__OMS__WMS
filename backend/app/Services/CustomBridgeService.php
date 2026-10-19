<?php

namespace App\Services;

use App\Models\CustomBridgeNonce;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use RuntimeException;

class CustomBridgeService
{
    /**
     * Authenticate and verify incoming Custom Bridge request.
     */
    public function verifyRequest(Request $request): Store
    {
        $apiKey = $request->header('X-Bridge-Api-Key');
        $timestamp = (int) $request->header('X-Bridge-Timestamp');
        $nonce = $request->header('X-Bridge-Nonce');
        $signature = $request->header('X-Bridge-Signature');

        if (! $apiKey || ! $timestamp || ! $nonce || ! $signature) {
            throw new RuntimeException('Missing required Finovo Bridge security headers.');
        }

        // Validate timestamp window (300 seconds)
        $now = time();
        if (abs($now - $timestamp) > 300) {
            throw new RuntimeException('Request timestamp is outside the allowed 300-second window.');
        }

        // Find store by API Key
        $store = Store::where('api_key', $apiKey)->where('platform', 'custom_bridge')->first();
        if (! $store) {
            throw new RuntimeException('Invalid Bridge API Key.');
        }

        // Prevent Replay Attacks using atomic nonce insertion
        try {
            CustomBridgeNonce::create([
                'store_id' => $store->id,
                'nonce' => $nonce,
            ]);
        } catch (QueryException $e) {
            throw new RuntimeException('Nonce already used (replay attack prevented).');
        }

        // Verify HMAC-SHA256 Signature
        $rawBody = $request->getContent();
        $canonical = "{$timestamp}\n{$nonce}\n{$rawBody}";
        $expectedSignature = hash_hmac('sha256', $canonical, $store->api_secret ?: '');

        if (! hash_equals($expectedSignature, $signature)) {
            throw new RuntimeException('Invalid HMAC signature.');
        }

        return $store;
    }
}
