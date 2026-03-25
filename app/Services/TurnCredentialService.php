<?php

namespace App\Services;

class TurnCredentialService
{
    /**
     * Generate time-limited TURN credentials using HMAC-SHA1.
     * Compatible with Coturn REST API (--use-auth-secret).
     *
     * Returns ice_servers array (STUN + TURN) per WebRTC spec format.
     */
    public function generateCredentials(int $userId): array
    {
        $secret = config('services.turn.secret');
        $ttl = config('services.turn.ttl', 86400);
        $urls = config('services.turn.urls', []);

        if (!$secret || empty($urls)) {
            return [
                'ice_servers' => [],
                'ttl_seconds' => 0,
            ];
        }

        $expiry = time() + $ttl;
        $username = $expiry . ':' . $userId;
        $credential = base64_encode(hash_hmac('sha1', $username, $secret, true));

        $iceServers = [];

        // Add STUN server (first URL as STUN, no auth needed)
        foreach ($urls as $url) {
            $stunUrl = str_replace('turn:', 'stun:', $url);
            $iceServers[] = ['urls' => $stunUrl];
            break; // Only one STUN entry
        }

        // Add TURN servers with credentials
        foreach ($urls as $url) {
            $iceServers[] = [
                'urls' => $url,
                'username' => $username,
                'credential' => $credential,
            ];
        }

        return [
            'ice_servers' => $iceServers,
            'ttl_seconds' => $ttl,
        ];
    }
}
