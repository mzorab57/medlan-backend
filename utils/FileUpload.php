<?php
class FileUpload
{
    public static function save(string $key, string $destDir, array $allowedExts = [], int $maxSize = null, string $newName = null)
    {
        if (!isset($_FILES[$key])) {
            return [false, 'file missing', null];
        }
        $file = $_FILES[$key];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [false, 'upload error', null];
        }
        if ($maxSize !== null && $file['size'] > $maxSize) {
            return [false, 'file too large', null];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($allowedExts && !in_array($ext, $allowedExts, true)) {
            return [false, 'invalid extension', null];
        }
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0775, true)) {
                return [false, 'cannot create directory', null];
            }
        }
        if (!is_writable($destDir)) {
            @chmod($destDir, 0777);
            if (!is_writable($destDir)) {
                return [false, 'destination not writable', null];
            }
        }
        $base = pathinfo($file['name'], PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^A-Za-z0-9_\-]+/', '-', $base);
        $safeBase = trim($safeBase, '-');
        if ($safeBase === '') {
            $safeBase = 'upload';
        }
        $suffix = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $filename = $newName ? $newName : ($safeBase . '-' . $suffix . '.' . $ext);
        $target = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return [false, 'cannot move file', null];
        }
        return [true, 'ok', $target];
    }
}
