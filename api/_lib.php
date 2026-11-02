<?php
// Shared helpers for the shop API. Not reachable from the web (api/.htaccess).
//
// SECRETS: nothing secret lives in this repo. Keys are read from a private
// config file OUTSIDE public_html:
//   /home1/fydicimy/magnet-fairy-private/config.php
// (or the path in the MF_CONFIG environment variable, for local testing).
// See SHOP-SETUP.md for the template.

declare(strict_types=1);

const MF_VERSION = '2026-11-01';

date_default_timezone_set('America/New_York');

// Never run as a page of its own.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function mf_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $path = getenv('MF_CONFIG');
    if (!$path) {
        $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__), '/');
        $path = dirname($docroot) . '/magnet-fairy-private/config.php';
    }
    if (!is_file($path)) {
        mf_log('Config file missing: ' . $path);
        mf_json(['error' => 'The shop is not set up yet. Please email scott@themagnetfairy.com.'], 503);
    }
    $cfg = require $path;
    $cfg['private_dir'] = $cfg['private_dir'] ?? dirname($path);
    foreach (['orders', 'logs', 'ratelimit'] as $sub) {
        $dir = $cfg['private_dir'] . '/' . $sub;
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }
    return $cfg;
}

function mf_log(string $msg): void
{
    $dir = null;
    $path = getenv('MF_CONFIG');
    if ($path) {
        $dir = dirname($path) . '/logs';
    } else {
        $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__), '/');
        $dir = dirname($docroot) . '/magnet-fairy-private/logs';
    }
    if (is_dir($dir)) {
        @file_put_contents($dir . '/shop-' . date('Y-m') . '.log', '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND);
    } else {
        error_log('[magnet-fairy] ' . $msg);
    }
}

function mf_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function mf_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        mf_json(['error' => 'POST only'], 405);
    }
}

function mf_read_json(int $maxBytes = 200000): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) {
        mf_json(['error' => 'Request too large'], 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        mf_json(['error' => 'Bad request'], 400);
    }
    return $data;
}

function mf_valid_cart_id($id): bool
{
    return is_string($id) && preg_match('/^[a-z0-9]{16,32}$/', $id) === 1;
}

function mf_catalog(): array
{
    $json = file_get_contents(dirname(__DIR__) . '/shop/products.json');
    $cat = json_decode((string) $json, true);
    if (!is_array($cat) || empty($cat['sizes'])) {
        mf_json(['error' => 'Catalog unavailable'], 500);
    }
    return $cat;
}

// Simple per-IP limiter: at most $max hits per $window seconds per bucket.
function mf_rate_limit(string $bucket, int $max, int $window): void
{
    $cfg = mf_config();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $file = $cfg['private_dir'] . '/ratelimit/' . $bucket . '-' . hash('sha256', $ip . ($cfg['rate_salt'] ?? '')) . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $hits = json_decode((string) file_get_contents($file), true) ?: [];
    }
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - $window));
    if (count($hits) >= $max) {
        mf_json(['error' => 'Too many requests. Please wait a few minutes and try again.'], 429);
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
}

// ---------------------------------------------------------------- HTTP

function mf_http(string $method, string $url, array $headers, ?string $body): array
{
    $cfg = mf_config();
    // Local tests can point these at a mock server.
    if (!empty($cfg['mock_base'])) {
        $url = preg_replace('#^https://[^/]+#', rtrim($cfg['mock_base'], '/') . '/' . parse_url($url, PHP_URL_HOST), $url);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['status' => 0, 'body' => null, 'raw' => $err];
    }
    return ['status' => $status, 'body' => json_decode($resp, true), 'raw' => $resp];
}

// ---------------------------------------------------------------- Stripe

function mf_stripe(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
{
    $cfg = mf_config();
    $headers = [
        'Authorization: Bearer ' . $cfg['stripe_secret_key'],
        'Content-Type: application/x-www-form-urlencoded',
    ];
    if ($idempotencyKey) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $body = null;
    if ($method === 'GET') {
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
    } else {
        $body = http_build_query($params);
    }
    $res = mf_http($method, $url, $headers, $body);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        $msg = $res['body']['error']['message'] ?? $res['raw'];
        throw new RuntimeException('Stripe ' . $path . ' failed (' . $res['status'] . '): ' . $msg);
    }
    return $res['body'];
}

// Verifies the Stripe-Signature header. Returns the decoded event.
function mf_stripe_verify(string $payload, string $sigHeader, string $secret, int $tolerance = 300): array
{
    $t = null;
    $sigs = [];
    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) {
            continue;
        }
        if ($kv[0] === 't') {
            $t = (int) $kv[1];
        } elseif ($kv[0] === 'v1') {
            $sigs[] = $kv[1];
        }
    }
    if (!$t || !$sigs) {
        throw new RuntimeException('Malformed signature header');
    }
    if (abs(time() - $t) > $tolerance) {
        throw new RuntimeException('Signature timestamp outside tolerance');
    }
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($sigs as $s) {
        if (hash_equals($expected, $s)) {
            $event = json_decode($payload, true);
            if (!is_array($event)) {
                throw new RuntimeException('Bad JSON');
            }
            return $event;
        }
    }
    throw new RuntimeException('No matching signature');
}

// ---------------------------------------------------------------- Dropbox

// Saved by api/dropbox-connect.php; a value in the config file also works.
function mf_dropbox_refresh_token(): string
{
    $cfg = mf_config();
    $file = $cfg['private_dir'] . '/dropbox-refresh.json';
    if (is_file($file)) {
        $t = json_decode((string) file_get_contents($file), true);
        if (!empty($t['refresh_token'])) {
            return $t['refresh_token'];
        }
    }
    if (!empty($cfg['dropbox_refresh_token'])) {
        return $cfg['dropbox_refresh_token'];
    }
    throw new RuntimeException('Dropbox is not connected yet (run api/dropbox-connect.php).');
}

function mf_dropbox_token(): string
{
    $cfg = mf_config();
    $cache = $cfg['private_dir'] . '/dropbox-token.json';
    if (is_file($cache)) {
        $tok = json_decode((string) file_get_contents($cache), true);
        if (!empty($tok['access_token']) && ($tok['expires_at'] ?? 0) > time() + 120) {
            return $tok['access_token'];
        }
    }
    $res = mf_http('POST', 'https://api.dropboxapi.com/oauth2/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => mf_dropbox_refresh_token(),
        'client_id' => $cfg['dropbox_app_key'],
        'client_secret' => $cfg['dropbox_app_secret'],
    ]));
    if ($res['status'] !== 200 || empty($res['body']['access_token'])) {
        throw new RuntimeException('Dropbox token refresh failed (' . $res['status'] . '): ' . $res['raw']);
    }
    $tok = [
        'access_token' => $res['body']['access_token'],
        'expires_at' => time() + (int) ($res['body']['expires_in'] ?? 14400),
    ];
    @file_put_contents($cache, json_encode($tok), LOCK_EX);
    @chmod($cache, 0600);
    return $tok['access_token'];
}

function mf_dropbox(string $endpoint, array $args, bool $allowConflict = false): array
{
    $res = mf_http('POST', 'https://api.dropboxapi.com/2/' . $endpoint, [
        'Authorization: Bearer ' . mf_dropbox_token(),
        'Content-Type: application/json',
    ], json_encode($args));
    if ($res['status'] === 409 && $allowConflict) {
        return ['_conflict' => true, 'error' => $res['body']['error'] ?? null, 'error_summary' => $res['body']['error_summary'] ?? ''];
    }
    if ($res['status'] !== 200) {
        throw new RuntimeException('Dropbox ' . $endpoint . ' failed (' . $res['status'] . '): ' . $res['raw']);
    }
    return $res['body'] ?? [];
}

// Uploads a small text file (order.txt etc.).
function mf_dropbox_put(string $path, string $contents): array
{
    $res = mf_http('POST', 'https://content.dropboxapi.com/2/files/upload', [
        'Authorization: Bearer ' . mf_dropbox_token(),
        'Content-Type: application/octet-stream',
        'Dropbox-API-Arg: ' . mf_dropbox_arg(['path' => $path, 'mode' => 'overwrite', 'mute' => true]),
    ], $contents);
    if ($res['status'] !== 200) {
        throw new RuntimeException('Dropbox upload failed (' . $res['status'] . '): ' . $res['raw']);
    }
    return $res['body'] ?? [];
}

// Dropbox-API-Arg must be ASCII: escape non-ASCII characters.
function mf_dropbox_arg(array $args): string
{
    return json_encode($args, JSON_UNESCAPED_SLASHES);
}

function mf_order_file(string $cartId): string
{
    return mf_config()['private_dir'] . '/orders/' . $cartId . '.json';
}

function mf_load_order(string $cartId): ?array
{
    $f = mf_order_file($cartId);
    if (!is_file($f)) {
        return null;
    }
    $o = json_decode((string) file_get_contents($f), true);
    return is_array($o) ? $o : null;
}

function mf_save_order(array $order): void
{
    file_put_contents(mf_order_file($order['cart_id']), json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function mf_money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}
