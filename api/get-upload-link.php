<?php
// POST {cart_id, photo_id, kind: "original"|"print", filename, bytes}
// -> {link, path}
//
// Returns a one-time Dropbox upload link (valid 4 hours, up to 150 MB) so the
// browser can send the ORIGINAL photo straight to Scott's Dropbox app folder
// at full resolution. Files land in /Pending/<cart_id>/ until the order is paid.

declare(strict_types=1);
require __DIR__ . '/_lib.php';

mf_require_post();
mf_config();
mf_rate_limit('upload', 240, 3600);

$in = mf_read_json(4000);
$cartId = $in['cart_id'] ?? null;
$photoId = $in['photo_id'] ?? null;
$kind = $in['kind'] ?? null;
$filename = (string) ($in['filename'] ?? '');
$bytes = (int) ($in['bytes'] ?? 0);

if (!mf_valid_cart_id($cartId)) {
    mf_json(['error' => 'Bad cart id'], 400);
}
if (!is_string($photoId) || !preg_match('/^[a-z0-9]{6,16}$/', $photoId)) {
    mf_json(['error' => 'Bad photo id'], 400);
}
if (!in_array($kind, ['original', 'print'], true)) {
    mf_json(['error' => 'Bad kind'], 400);
}
if ($bytes <= 0 || $bytes > 150 * 1024 * 1024) {
    mf_json(['error' => 'Photos must be under 150 MB.'], 400);
}
if (!preg_match('/\.(jpe?g|png)$/i', $filename)) {
    mf_json(['error' => 'Please choose a JPEG or PNG photo.'], 400);
}
$order = mf_load_order($cartId);
if ($order && !empty($order['paid'])) {
    mf_json(['error' => 'This order was already placed. Please refresh the page to start a new one.'], 409);
}

$safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
$safe = substr(trim($safe, '-'), -60) ?: 'photo.jpg';
$path = '/Pending/' . $cartId . '/' . $photoId . '-' . $kind . '-' . $safe;

try {
    $res = mf_dropbox('files/get_temporary_upload_link', [
        'commit_info' => [
            'path' => $path,
            'mode' => 'overwrite',
            'autorename' => false,
            'mute' => true,
        ],
        'duration' => 14400,
    ]);
} catch (Throwable $e) {
    mf_log('get-upload-link: ' . $e->getMessage());
    mf_json(['error' => 'Photo upload is having trouble right now. Please try again in a minute.'], 502);
}

mf_json(['link' => $res['link'], 'path' => $path]);
