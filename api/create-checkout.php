<?php
// POST {cart_id, items: [{size, pack, photos: [{id, name, w, h, orientation,
//       crop, original_path, print_path}]}]}
// -> {url}  (Stripe-hosted Checkout page)
//
// Prices come from shop/products.json ON THE SERVER, never from the browser.
// Every photo must already be in /Pending/<cart_id>/ in Dropbox.

declare(strict_types=1);
require __DIR__ . '/_lib.php';

mf_require_post();
$cfg = mf_config();
mf_rate_limit('checkout', 30, 3600);

$in = mf_read_json();
$cartId = $in['cart_id'] ?? null;
$items = $in['items'] ?? null;
if (!mf_valid_cart_id($cartId) || !is_array($items) || !$items) {
    mf_json(['error' => 'Your cart looks empty. Please add a pack and try again.'], 400);
}
if (count($items) > 40) {
    mf_json(['error' => 'That is a big order! Please email scott@themagnetfairy.com and I will set it up for you.'], 400);
}

$existing = mf_load_order($cartId);
if ($existing && !empty($existing['paid'])) {
    mf_json(['error' => 'This order was already placed. Your cart has been cleared so you can start a new one.'], 409);
}

$cat = mf_catalog();
$prefix = '/Pending/' . $cartId . '/';
$clean = [];
$lineItems = [];
$subtotal = 0;
$magnetCount = 0;

foreach ($items as $n => $it) {
    $size = $it['size'] ?? null;
    $pack = (int) ($it['pack'] ?? 0);
    if (!is_string($size) || !isset($cat['sizes'][$size])) {
        mf_json(['error' => 'Unknown magnet size in your cart. Please refresh and try again.'], 400);
    }
    $s = $cat['sizes'][$size];
    if (!isset($s['packs'][(string) $pack])) {
        mf_json(['error' => 'Unknown pack in your cart. Please refresh and try again.'], 400);
    }
    $photos = $it['photos'] ?? [];
    if (!is_array($photos) || count($photos) !== $pack) {
        mf_json(['error' => 'Each magnet needs a photo. Please check your cart.'], 400);
    }
    $cleanPhotos = [];
    foreach ($photos as $p) {
        $orig = (string) ($p['original_path'] ?? '');
        $print = (string) ($p['print_path'] ?? '');
        foreach ([$orig, $print] as $path) {
            if (strpos($path, $prefix) !== 0 || strpos($path, '..') !== false || strlen($path) > 200) {
                mf_json(['error' => 'A photo is missing. Please remove that pack and add it again.'], 400);
            }
        }
        $orientation = in_array($p['orientation'] ?? '', $s['orientations'], true) ? $p['orientation'] : $s['orientations'][0];
        $crop = $p['crop'] ?? [];
        $cleanPhotos[] = [
            'id' => preg_replace('/[^a-z0-9]/', '', (string) ($p['id'] ?? '')),
            'name' => mb_substr((string) ($p['name'] ?? ''), 0, 120),
            'w' => (int) ($p['w'] ?? 0),
            'h' => (int) ($p['h'] ?? 0),
            'orientation' => $orientation,
            'crop' => [
                'x' => (int) ($crop['x'] ?? 0),
                'y' => (int) ($crop['y'] ?? 0),
                'width' => (int) ($crop['width'] ?? 0),
                'height' => (int) ($crop['height'] ?? 0),
                'rotate' => (int) ($crop['rotate'] ?? 0),
            ],
            'original_path' => $orig,
            'print_path' => $print,
        ];
    }
    $price = (int) $s['packs'][(string) $pack];
    $subtotal += $price;
    $magnetCount += $pack;
    $label = $s['name'] . ($pack > 1 ? ' - ' . $pack . '-pack' : '');
    $clean[] = ['size' => $size, 'pack' => $pack, 'price' => $price, 'label' => $label, 'photos' => $cleanPhotos];

    $productData = [
        'name' => $label,
        'description' => $s['dimensions'] . ', ' . $pack . ' custom photo magnet' . ($pack > 1 ? 's' : ''),
    ];
    if (($cfg['tax_mode'] ?? 'none') === 'stripe_tax') {
        $productData['tax_code'] = $cfg['product_tax_code'] ?? 'txcd_99999999';
    }
    $lineItems[] = [
        'quantity' => 1,
        'price_data' => [
            'currency' => $cat['currency'],
            'unit_amount' => $price,
            'tax_behavior' => 'exclusive',
            'product_data' => $productData,
        ],
    ];
}

// Every photo must really be in Dropbox before we take money for it.
try {
    $found = [];
    $res = mf_dropbox('files/list_folder', ['path' => rtrim($prefix, '/'), 'limit' => 2000], true);
    if (!empty($res['_conflict'])) {
        mf_json(['error' => 'Your photos did not finish uploading. Please remove the pack and add it again.'], 400);
    }
    while (true) {
        foreach ($res['entries'] ?? [] as $e) {
            $found[strtolower($e['path_lower'] ?? '')] = true;
        }
        if (empty($res['has_more'])) {
            break;
        }
        $res = mf_dropbox('files/list_folder/continue', ['cursor' => $res['cursor']]);
    }
    foreach ($clean as $it) {
        foreach ($it['photos'] as $p) {
            if (empty($found[strtolower($p['original_path'])]) || empty($found[strtolower($p['print_path'])])) {
                mf_json(['error' => 'One of your photos did not finish uploading. Please remove that pack and add it again.'], 400);
            }
        }
    }
} catch (Throwable $e) {
    mf_log('create-checkout dropbox check: ' . $e->getMessage());
    mf_json(['error' => 'Checkout is having trouble right now. Please try again in a minute.'], 502);
}

$base = rtrim($cfg['site_url'] ?? 'https://themagnetfairy.com', '/');
$shipping = [
    'type' => 'fixed_amount',
    'display_name' => $cat['shipping_label'] ?? 'Shipping',
    'fixed_amount' => ['amount' => (int) $cat['shipping_cents'], 'currency' => $cat['currency']],
    'tax_behavior' => 'exclusive',
    'delivery_estimate' => [
        'minimum' => ['unit' => 'business_day', 'value' => 4],
        'maximum' => ['unit' => 'business_day', 'value' => 9],
    ],
];
if (($cfg['tax_mode'] ?? 'none') === 'stripe_tax') {
    $shipping['tax_code'] = 'txcd_92010001';
}

$params = [
    'mode' => 'payment',
    'line_items' => $lineItems,
    'shipping_address_collection' => ['allowed_countries' => ['US']],
    'shipping_options' => [['shipping_rate_data' => $shipping]],
    'phone_number_collection' => ['enabled' => 'true'],
    'billing_address_collection' => 'required',
    'client_reference_id' => $cartId,
    'metadata' => ['cart_id' => $cartId, 'order_number' => strtoupper(substr($cartId, -6)), 'magnets' => (string) $magnetCount],
    'payment_intent_data' => [
        'description' => 'The Magnet Fairy order #' . strtoupper(substr($cartId, -6)),
        'metadata' => ['cart_id' => $cartId],
    ],
    'success_url' => $base . '/shop/thanks.html?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => $base . '/shop/?canceled=1#cart',
    'expires_at' => time() + 3600 * 6,
];
if (($cfg['tax_mode'] ?? 'none') === 'stripe_tax') {
    $params['automatic_tax'] = ['enabled' => 'true'];
}

try {
    // Same cart + same contents = same session (safe against double clicks).
    $idem = 'mf-' . $cartId . '-' . substr(hash('sha256', json_encode($clean)), 0, 16) . '-' . date('YmdH');
    $session = mf_stripe('POST', 'checkout/sessions', $params, $idem);
} catch (Throwable $e) {
    mf_log('create-checkout stripe: ' . $e->getMessage());
    mf_json(['error' => 'Checkout is having trouble right now. Please try again in a minute.'], 502);
}

mf_save_order([
    'cart_id' => $cartId,
    'order_number' => strtoupper(substr($cartId, -6)),
    'created' => date('c'),
    'session_id' => $session['id'],
    'livemode' => !empty($session['livemode']),
    'subtotal' => $subtotal,
    'shipping' => (int) $cat['shipping_cents'],
    'items' => $clean,
    'paid' => false,
]);

mf_json(['url' => $session['url']]);
