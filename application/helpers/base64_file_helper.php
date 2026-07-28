<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('save_base64_file')) {
    /**
     * Decode base64 string, validate MIME, save to disk.
     *
     * @param string $base64_input  Raw base64 string (may include data:// prefix)
     * @param string $upload_dir    Absolute path to save directory
     * @param array  $allowed_mimes Allowed MIME types, e.g. ['image/jpeg', 'image/png']
     * @param int    $max_bytes     Max file size in bytes
     * @return array ['success'=>true, 'file_name'=>..., 'file_path'=>..., 'mime'=>..., 'size'=>...]
     *               OR ['success'=>false, 'error'=>..., 'status'=>400|415|500]
     */
    function save_base64_file($base64_input, $upload_dir, $allowed_mimes = [], $max_bytes = 2097152) {
        // 1. Strip data:// prefix (e.g. data:image/jpeg;base64,xxxx)
        if (strpos($base64_input, 'data:') === 0) {
            $parts = explode('base64,', $base64_input, 2);
            if (count($parts) !== 2) {
                return ['success' => false, 'error' => 'Format base64 tidak valid', 'status' => 400];
            }
            $base64_input = $parts[1];
        }

        // 2. Reject oversized base64 BEFORE decode (prevent memory exhaustion)
        $max_encoded = (int) ceil($max_bytes / 3) * 4;
        if (strlen($base64_input) > $max_encoded) {
            return ['success' => false, 'error' => 'Ukuran file melebihi batas maksimum ' . ($max_bytes / 1024) . ' KB', 'status' => 400];
        }

        // 3. Decode
        $binary = base64_decode($base64_input, true);
        if ($binary === false) {
            return ['success' => false, 'error' => 'Data base64 tidak valid', 'status' => 400];
        }

        // 4. Size check
        $file_size = strlen($binary);
        if ($file_size > $max_bytes) {
            return ['success' => false, 'error' => 'Ukuran file melebihi batas maksimum ' . ($max_bytes / 1024) . ' KB', 'status' => 400];
        }

        // 5. MIME detection via finfo (reads magic bytes, not extension)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected_mime = finfo_buffer($finfo, $binary);
        finfo_close($finfo);

        if (!in_array($detected_mime, $allowed_mimes, true)) {
            return ['success' => false, 'error' => 'Format file tidak didukung', 'status' => 415];
        }

        // 6. Extension from MIME
        $ext_map = [
            'image/jpeg'          => 'jpg',
            'image/png'           => 'png',
            'application/pdf'     => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];
        $ext = isset($ext_map[$detected_mime]) ? $ext_map[$detected_mime] : 'bin';

        // 7. Unique safe filename
        $file_name = bin2hex(random_bytes(16)) . '.' . $ext;

        // 8. Ensure directory
        $upload_dir = rtrim($upload_dir, '/') . '/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // 9. Write to disk
        $full_path = $upload_dir . $file_name;
        $written = file_put_contents($full_path, $binary);
        if ($written === false) {
            return ['success' => false, 'error' => 'Gagal menyimpan file ke disk', 'status' => 500];
        }

        return [
            'success'   => true,
            'file_name' => $file_name,
            'file_path' => $full_path,
            'mime'      => $detected_mime,
            'size'      => $file_size,
        ];
    }
}
