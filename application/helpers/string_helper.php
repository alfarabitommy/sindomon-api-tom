<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('randomString')) {
    function randomString($length = 32)
        {
            return substr(bin2hex(random_bytes($length)), 0, $length);
        }
}