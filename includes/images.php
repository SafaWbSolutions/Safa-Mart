<?php
/**
 * Local image pipeline: validate, resize and store product images as WebP.
 */

/**
 * @return array{url:string,relative:string,path:string,width:int,height:int,bytes:int}
 */
function save_image_binary_as_webp(string $binary, string $slug): array
{
    if ($binary === '') {
        throw new RuntimeException('The image file is empty.');
    }
    if (strlen($binary) > UPLOAD_MAX_BYTES) {
        throw new RuntimeException('The image exceeds the maximum allowed size.');
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
        throw new RuntimeException('PHP GD with WebP support is required on this server.');
    }

    $info = @getimagesizefromstring($binary);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        throw new RuntimeException('The file is not a valid image.');
    }

    $width = (int) $info[0];
    $height = (int) $info[1];
    $type = (int) ($info[2] ?? 0);
    $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
    if (!in_array($type, $allowedTypes, true)) {
        throw new RuntimeException('Only JPEG, PNG, GIF and WebP images are supported.');
    }
    if ($width < 1 || $height < 1 || ($width * $height) > 50000000) {
        throw new RuntimeException('The image dimensions are not supported.');
    }

    $source = @imagecreatefromstring($binary);
    if ($source === false) {
        throw new RuntimeException('The image could not be decoded.');
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $scale = min(1.0, IMAGE_MAX_DIMENSION / max($sourceWidth, $sourceHeight));
    $targetWidth = max(1, (int) round($sourceWidth * $scale));
    $targetHeight = max(1, (int) round($sourceHeight * $scale));

    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($canvas === false) {
        imagedestroy($source);
        throw new RuntimeException('The image canvas could not be created.');
    }

    try {
        if (function_exists('imagepalettetotruecolor') && !imageistruecolor($source)) {
            @imagepalettetotruecolor($source);
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagealphablending($canvas, true);

        if (!imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        )) {
            throw new RuntimeException('The image could not be resized.');
        }

        $directory = UPLOAD_DIR . '/' . gmdate('Y') . '/' . gmdate('m');
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('The upload directory is not writable.');
        }

        $safeSlug = strtolower($slug);
        $safeSlug = preg_replace('/[^a-z0-9]+/', '-', $safeSlug) ?? '';
        $safeSlug = trim($safeSlug, '-');
        if ($safeSlug === '') {
            $safeSlug = 'product';
        }
        $safeSlug = substr($safeSlug, 0, 70);

        $filename = $safeSlug . '-' . bin2hex(random_bytes(5)) . '.webp';
        $path = $directory . '/' . $filename;
        if (!@imagewebp($canvas, $path, WEBP_QUALITY)) {
            throw new RuntimeException('The WebP image could not be saved.');
        }
        @chmod($path, 0644);
        clearstatcache(true, $path);

        $relative = UPLOAD_WEB_PATH . '/' . gmdate('Y') . '/' . gmdate('m') . '/' . $filename;
        return [
            'url'      => site_url($relative),
            'relative' => $relative,
            'path'     => $path,
            'width'    => $targetWidth,
            'height'   => $targetHeight,
            'bytes'    => (int) (filesize($path) ?: 0),
        ];
    } finally {
        imagedestroy($source);
        imagedestroy($canvas);
    }
}

/**
 * @return array{url:string,relative:string,path:string,width:int,height:int,bytes:int}
 */
function download_image_as_webp(string $imageUrl, string $slug, int $timeout = HTTP_TIMEOUT, string $referer = ''): array
{
    if (!is_safe_remote_url($imageUrl)) {
        throw new RuntimeException('The image URL is invalid or non-public.');
    }

    $response = http_request($imageUrl, [
        'timeout'   => max(5, min(30, $timeout)),
        'max_bytes' => UPLOAD_MAX_BYTES,
        'accept'    => 'image/webp,image/jpeg,image/png,image/gif,image/*;q=0.8,*/*;q=0.5',
        'referer'   => $referer !== '' ? $referer : origin_of_url($imageUrl),
    ]);

    if (!$response['ok'] || $response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException('The product image could not be downloaded.');
    }

    $contentType = strtolower($response['content_type']);
    if ($contentType !== ''
        && strpos($contentType, 'image/') !== 0
        && strpos($contentType, 'application/octet-stream') === false
        && strpos($contentType, 'binary/octet-stream') === false) {
        throw new RuntimeException('The image URL did not return an image.');
    }

    return save_image_binary_as_webp($response['body'], $slug);
}

/**
 * @return array{url:string,relative:string,path:string,width:int,height:int,bytes:int}
 */
function save_uploaded_file_as_webp(string $tmpPath, string $slug): array
{
    if (!is_file($tmpPath) || !is_readable($tmpPath)) {
        throw new RuntimeException('The uploaded file is unavailable.');
    }
    $size = @filesize($tmpPath);
    if ($size === false || $size < 1) {
        throw new RuntimeException('The uploaded file is empty.');
    }
    if ($size > UPLOAD_MAX_BYTES) {
        throw new RuntimeException('The uploaded image is too large.');
    }
    $binary = @file_get_contents($tmpPath);
    if ($binary === false) {
        throw new RuntimeException('The uploaded file could not be read.');
    }
    return save_image_binary_as_webp($binary, $slug);
}

function upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'The uploaded image is larger than the server limit.';
        case UPLOAD_ERR_PARTIAL:
            return 'The image upload was incomplete.';
        case UPLOAD_ERR_NO_FILE:
            return 'No image file was uploaded.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'The server upload directory is missing.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not save the upload.';
        case UPLOAD_ERR_EXTENSION:
            return 'An upload extension blocked the request.';
        default:
            return 'The image upload failed.';
    }
}
