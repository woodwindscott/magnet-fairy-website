<?php
// One-time setup: connects the shop to Scott's Dropbox app.
//
// Visit https://themagnetfairy.com/api/dropbox-connect.php?key=<setup_key>
// (setup_key is in the private config). Dropbox asks Scott to Allow, then
// sends him back here, and the long-lived refresh token is saved to the
// private folder. No secrets are ever shown on screen or pasted anywhere.

declare(strict_types=1);
require __DIR__ . '/_lib.php';

$cfg = mf_config();
$tokenFile = $cfg['private_dir'] . '/dropbox-refresh.json';
$stateFile = $cfg['private_dir'] . '/dropbox-oauth-state.json';
$redirect = rtrim($cfg['site_url'] ?? 'https://themagnetfairy.com', '/') . '/api/dropbox-connect.php';

function page(string $title, string $msg, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . '<body style="font:17px/1.5 system-ui,sans-serif;max-width:560px;margin:60px auto;padding:0 16px;color:#201e1d;background:#f3f2f2">'
        . '<h1 style="font-size:24px">' . htmlspecialchars($title) . '</h1><p>' . $msg . '</p></body>';
    exit;
}

if (empty($cfg['dropbox_app_key']) || empty($cfg['dropbox_app_secret'])) {
    page('Dropbox app key missing', 'Add dropbox_app_key and dropbox_app_secret to the private config first.', 500);
}

// Step 2: Dropbox sent us back with a code.
if (isset($_GET['code'], $_GET['state'])) {
    $saved = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
    @unlink($stateFile);
    if (!$saved || !hash_equals((string) $saved['state'], (string) $_GET['state']) || $saved['expires'] < time()) {
        page('Link expired', 'That sign-in link expired. Start again from the setup link.', 400);
    }
    $res = mf_http('POST', 'https://api.dropboxapi.com/oauth2/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'code' => (string) $_GET['code'],
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirect,
        'client_id' => $cfg['dropbox_app_key'],
        'client_secret' => $cfg['dropbox_app_secret'],
    ]));
    if ($res['status'] !== 200 || empty($res['body']['refresh_token'])) {
        mf_log('dropbox-connect exchange failed: ' . $res['raw']);
        page('Dropbox did not connect', 'Dropbox refused the connection. Check the shop log in magnet-fairy-private/logs.', 502);
    }
    file_put_contents($tokenFile, json_encode([
        'refresh_token' => $res['body']['refresh_token'],
        'account_id' => $res['body']['account_id'] ?? '',
        'connected' => date('c'),
    ]), LOCK_EX);
    @chmod($tokenFile, 0600);
    @unlink($cfg['private_dir'] . '/dropbox-token.json');
    page('Dropbox connected', 'The shop can now save customer photos to Dropbox/Apps/' . htmlspecialchars($cfg['dropbox_app_folder'] ?? '') . '. You can close this tab.');
}

if (isset($_GET['error'])) {
    page('Dropbox not connected', 'You chose not to allow access. Start again from the setup link when you are ready.', 400);
}

// Step 1: check the setup key, then send Scott to Dropbox.
$key = (string) ($_GET['key'] ?? '');
if (empty($cfg['setup_key']) || !hash_equals((string) $cfg['setup_key'], $key)) {
    page('Not found', 'Nothing here.', 404);
}
if (is_file($tokenFile) && !isset($_GET['reconnect'])) {
    page('Already connected', 'Dropbox is already connected. To connect a different account, add &amp;reconnect=1 to the link.');
}
$state = bin2hex(random_bytes(16));
file_put_contents($stateFile, json_encode(['state' => $state, 'expires' => time() + 900]), LOCK_EX);
header('Location: https://www.dropbox.com/oauth2/authorize?' . http_build_query([
    'client_id' => $cfg['dropbox_app_key'],
    'response_type' => 'code',
    'token_access_type' => 'offline',
    'redirect_uri' => $redirect,
    'state' => $state,
]));
exit;
