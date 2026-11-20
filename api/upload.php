<?php
/**
 * Authenticated image upload endpoint for the scheduled GitHub Actions sync.
 * Accepts multipart "image", JSON {"image_url": "..."} or JSON {"image_base64": "..."}.
 * Always resizes and converts to WebP on this server.
 */

require_once dirname(__DIR__) . '/config.php';
security_headers();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$providedSecret = (string) ($_SERVER['HTTP_X_API_SECRET'] ?? '');
if (UPLOAD_API_SECRET === '' || $providedSecret === '' || !hash_equals(UPLOAD_API_SECRET, $providedSecret)) {
    app_log('warning', 'Upload rejected: invalid API secret', ['ip' => client_ip()]);
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

if (!rate_limit_hit('upload:' . client_ip(), 300, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many uploads. Try again later.'], 429);
}

$slugInput = '';
try {
    if (!empty($_FILES['image']) && is_array($_FILES['image'])) {
        $file = $_FILES['image'];
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException(upload_error_message($errorCode));
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('The uploaded file could not be verified.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > UPLOAD_MAX_BYTES) {
            throw new RuntimeException('The uploaded image is empty or too large.');
        }
        $slugInput = (string) ($_POST['slug'] ?? pathinfo((string) ($file['name'] ?? 'product'), PATHINFO_FILENAME));
        $saved = save_uploaded_file_as_webp($tmpPath, $slugInput !== '' ? slugify_title($slugInput) : 'product');
        json_response([
            'ok'       => true,
            'url'      => $saved['url'],
            'relative' => $saved['relative'],
            'width'    => $saved['width'],
            'height'   => $saved['height'],
            'bytes'    => $saved['bytes'],
        ]);
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '' || strlen($rawBody) > (UPLOAD_MAX_BYTES * 2)) {
        throw new RuntimeException('The request body is empty or too large.');
    }
    $input = json_decode($rawBody, true);
    if (!is_array($input)) {
        throw new RuntimeException('Send multipart form data or a JSON request body.');
    }

    $slugInput = (string) ($input['slug'] ?? 'product');
    $slug = slugify_title($slugInput !== '' ? $slugInput : 'product');

    if (!empty($input['image_url']) && is_string($input['image_url'])) {
        $imageUrl = trim($input['image_url']);
        if (!is_safe_remote_url($imageUrl)) {
            throw new RuntimeException('The image URL is invalid or non-public.');
        }
        $saved = download_image_as_webp($imageUrl, $slug, 15);
    } elseif (!empty($input['image_base64']) && is_string($input['image_base64'])) {
        $encoded = trim($input['image_base64']);
        if (str_starts_with($encoded, 'data:')) {
            $comma = strpos($encoded, ',');
            if ($comma === false) {
                throw new RuntimeException('The base64 data URI is malformed.');
            }
            $encoded = substr($encoded, $comma + 1);
        }
        $binary = base64_decode($encoded, true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('The base64 image could not be decoded.');
        }
        $saved = save_image_binary_as_webp($binary, $slug);
    } else {
        throw new RuntimeException('Provide an "image_url" or "image_base64" value.');
    }

    json_response([
        'ok'       => true,
        'url'      => $saved['url'],
        'relative' => $saved['relative'],
        'width'    => $saved['width'],
        'height'   => $saved['height'],
        'bytes'    => $saved['bytes'],
    ]);
} catch (Throwable $error) {
    app_log('error', 'Image upload failed', [
        'ip'    => client_ip(),
        'slug'  => $slugInput,
        'error' => $error->getMessage(),
    ]);
    json_response(['ok' => false, 'error' => $error->getMessage()], 422);
}
