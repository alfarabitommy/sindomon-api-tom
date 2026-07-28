<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Minimal JWT library for CodeIgniter 3.
 * HS256 decode only — no external dependencies.
 */
class Jwt {

    private $secret;

    public function __construct()
    {
        $this->load =& get_instance();
        $this->load->config->load('jwt');
        $this->secret = $this->load->config->item('jwt_secret');
    }

    /**
     * Decode a JWT token and return the payload array.
     * Returns FALSE on any failure (invalid format, expired, bad signature).
     */
    public function decode($token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return FALSE;
        }

        list($header64, $payload64, $signature64) = $parts;

        // Base64url decode
        $payload_str  = $this->base64UrlDecode($payload64);
        $signature    = $this->base64UrlDecode($signature64);

        if ($payload_str === FALSE || $signature === '') {
            return FALSE;
        }

        // Verify signature
        $expected = hash_hmac('sha256', $header64 . '.' . $payload64, $this->secret, true);
        if (!hash_equals($expected, $signature)) {
            return FALSE;
        }

        // Decode payload
        $payload = json_decode($payload_str, true);
        if (!$payload || !is_array($payload)) {
            return FALSE;
        }

        // Check expiry if present
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return FALSE;
        }

        return $payload;
    }

    /**
     * Base64url decode a string.
     */
    private function base64UrlDecode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
