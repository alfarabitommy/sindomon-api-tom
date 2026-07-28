<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('generate_uuid4')) {
    function generate_uuid4() {
        $data = random_bytes(16);

        // Set versi ke 0100 (v4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set variant ke 10xx (RFC 4122)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}