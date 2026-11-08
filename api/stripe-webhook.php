<?php
// Stripe webhook. Listens for:
//   checkout.session.completed               (card, Apple Pay, Google Pay, Link)
//   checkout.session.async_payment_succeeded (slower methods, if ever enabled)
//
// On a paid order:
//   1. Moves Dropbox /Pending/<cart_id>  ->  /Orders/YYYY-MM-DD Lastname #ABC123
//   2. Writes order.txt (customer, address, every magnet + its files and crop)
//   3. Emails Scott a summary with a link to the Dropbox folder
// Safe to receive the same event more than once.

declare(strict_types=1);
require __DIR__ . '/_lib.php';

mf_require_post();
$cfg = mf_config();

$payload = (string) file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
try {
    $event = mf_stripe_verify($payload, $sig, $cfg['stripe_webhook_secret']);
} catch (Throwable $e) {
    mf_log('webhook signature: ' . $e->getMessage());
    mf_json(['error' => 'Invalid signature'], 400);
}

$type = $event['type'] ?? '';
if (!in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
    mf_json(['received' => true, 'ignored' => $type]);
}

$session = $event['data']['object'] ?? [];
if (($session['payment_status'] ?? '') !== 'paid') {
    // async payment still pending; we'll get async_payment_succeeded later.
    mf_json(['received' => true, 'pending' => true]);
}

$cartId = $session['client_reference_id'] ?? ($session['metadata']['cart_id'] ?? '');
if (!mf_valid_cart_id($cartId)) {
    mf_log('webhook: session ' . ($session['id'] ?? '?') . ' has no cart id');
    mf_json(['received' => true, 'error' => 'no cart id']);
}

$order = mf_load_order($cartId);
if (!$order) {
    mf_log('webhook: no order draft for cart ' . $cartId);
    // Still tell Scott so a paid order is never silently lost.
    mf_notify($cfg, 'PAID order with missing details #' . strtoupper(substr($cartId, -6)),
        "Stripe says order #" . strtoupper(substr($cartId, -6)) . " was paid (session " . ($session['id'] ?? '?') . "),\n" .
        "but the shop has no record of its photos. Check Dropbox /Pending/" . $cartId . " and the Stripe Dashboard.\n");
    mf_json(['received' => true]);
}
if (!empty($order['finalized'])) {
    mf_json(['received' => true, 'duplicate' => true]);
}

try {
    // Full session with line items, so totals and tax are exact.
    $full = mf_stripe('GET', 'checkout/sessions/' . rawurlencode($session['id']), ['expand' => ['line_items', 'shipping_cost.shipping_rate']]);
    $result = mf_finalize($cfg, $order, $full);
} catch (Throwable $e) {
    mf_log('webhook finalize ' . $cartId . ': ' . $e->getMessage());
    // 500 makes Stripe retry later (up to 3 days).
    mf_json(['error' => 'finalize failed'], 500);
}

mf_json(['received' => true, 'folder' => $result['folder']]);

// ------------------------------------------------------------------------

function mf_customer(array $s): array
{
    $ship = $s['collected_information']['shipping_details'] ?? ($s['shipping_details'] ?? null);
    if (is_array($ship) && ($ship['name'] ?? '') === MF_PICKUP_NAME) {
        $ship = null; // pickup order: the "shipping" address is the Cary pickup spot, not the customer
    }
    $cd = $s['customer_details'] ?? [];
    $name = trim((string) ($ship['name'] ?? ($cd['name'] ?? 'Customer')));
    $addr = $ship['address'] ?? ($cd['address'] ?? []);
    return [
        'name' => $name !== '' ? $name : 'Customer',
        'email' => (string) ($cd['email'] ?? ''),
        'phone' => (string) ($cd['phone'] ?? ''),
        'address' => $addr ?: [],
        'billing_name' => (string) ($cd['name'] ?? ''),
        'billing_address' => $cd['address'] ?? [],
    ];
}

function mf_address_lines(array $a): string
{
    $lines = array_filter([
        $a['line1'] ?? '',
        $a['line2'] ?? '',
        trim(($a['city'] ?? '') . ', ' . ($a['state'] ?? '') . ' ' . ($a['postal_code'] ?? ''), ', '),
        ($a['country'] ?? '') !== 'US' ? ($a['country'] ?? '') : '',
    ]);
    return implode("\n", $lines);
}

function mf_last_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $last = end($parts) ?: 'Customer';
    $last = preg_replace('/[^A-Za-z0-9\'-]/', '', $last) ?: 'Customer';
    return ucfirst($last);
}

function mf_finalize(array $cfg, array $order, array $s): array
{
    $c = mf_customer($s);
    $num = $order['order_number'];
    $rate = $s['shipping_cost']['shipping_rate'] ?? null;
    $pickup = is_array($rate) && (($rate['metadata']['method'] ?? '') === 'pickup');
    $method = is_array($rate) ? (string) ($rate['display_name'] ?? '') : '';
    $date = date('Y-m-d', (int) ($s['created'] ?? time()));
    $test = empty($s['livemode']) ? ' TEST' : '';
    $folder = '/Orders/' . $date . ' ' . mf_last_name($c['name']) . ' #' . $num . ($pickup ? ' PICKUP' : '') . $test;
    $from = '/Pending/' . $order['cart_id'];

    // 1. Move the folder (skip if a retry already did it).
    if (empty($order['folder'])) {
        $mv = mf_dropbox('files/move_v2', ['from_path' => $from, 'to_path' => $folder, 'autorename' => true], true);
        if (!empty($mv['_conflict'])) {
            throw new RuntimeException('move failed: ' . ($mv['error_summary'] ?? ''));
        }
        $folder = $mv['metadata']['path_display'] ?? $folder;
        $order['folder'] = $folder;
        mf_save_order($order);
    }
    $folder = $order['folder'];

    // Paths in the order now live under the new folder.
    $rel = function (string $path) use ($order): string {
        return preg_replace('#^/Pending/' . preg_quote($order['cart_id'], '#') . '/#', '', $path);
    };

    // 2. order.txt
    $amountSub = (int) ($s['amount_subtotal'] ?? $order['subtotal']);
    $amountShip = (int) ($s['shipping_cost']['amount_subtotal'] ?? $order['shipping']); // before tax; tax on shipping is in $amountTax
    $amountTax = (int) ($s['total_details']['amount_tax'] ?? 0);
    $amountTotal = (int) ($s['amount_total'] ?? 0);

    $t = "THE MAGNET FAIRY ORDER #{$num}" . ($test ? '  (TEST MODE - not a real order)' : '') . "\n";
    $t .= "Paid: " . date('l, F j, Y g:i a', (int) ($s['created'] ?? time())) . "\n";
    $t .= str_repeat('=', 60) . "\n\n";
    if ($pickup) {
        $t .= ">>> LOCAL PICKUP in Cary. Email the customer to set a time. Do not ship. <<<\n\n";
        $t .= "CUSTOMER\n" . $c['name'] . "\n" . mf_address_lines($c['address']) . "\n\n";
    } else {
        $t .= "SHIP TO\n" . $c['name'] . "\n" . mf_address_lines($c['address']) . "\n\n";
    }
    $t .= "Email: " . $c['email'] . "\nPhone: " . $c['phone'] . "\n\n";
    if ($c['billing_name'] && $c['billing_name'] !== $c['name']) {
        $t .= "Billing name: " . $c['billing_name'] . "\n\n";
    }
    $t .= "TOTALS\n";
    $t .= "Magnets:  " . mf_money($amountSub) . "\n";
    $t .= "Shipping: " . mf_money($amountShip) . ($method !== '' ? "  (" . $method . ")" : '') . "\n";
    $t .= "Tax:      " . mf_money($amountTax) . "\n";
    $t .= "Total:    " . mf_money($amountTotal) . "\n\n";
    $t .= str_repeat('=', 60) . "\nMAGNETS\n";
    $t .= "Each magnet has two files:\n";
    $t .= "  PRINT    = ready to print, already cropped to the paper cut size at 300 dpi\n";
    $t .= "  ORIGINAL = the customer's full-resolution photo\n";
    $t .= "Crop numbers are pixels on the original as it displays upright.\n\n";
    $m = 0;
    $needCrop = 0;
    foreach ($order['items'] as $i => $it) {
        $t .= ($i + 1) . ". " . $it['label'] . "  (" . mf_money((int) $it['price']) . ")\n";
        foreach ($it['photos'] as $j => $p) {
            $m++;
            $cr = $p['crop'];
            $t .= "   Magnet {$m}: " . $p['orientation'] . "\n";
            if (!empty($p['scott_crops'])) {
                $needCrop++;
                $t .= "      *** CUSTOMER ASKED YOU TO CROP THIS ONE ***\n";
                $t .= "      ORIGINAL: " . $rel($p['original_path']) . "  (" . $p['w'] . " x " . $p['h'] . ")\n";
            } else {
                $t .= "      PRINT:    " . $rel($p['print_path']) . "\n";
                $t .= "      ORIGINAL: " . $rel($p['original_path']) . "  (" . $p['w'] . " x " . $p['h'] . ")\n";
                $t .= "      Crop: x={$cr['x']} y={$cr['y']} w={$cr['width']} h={$cr['height']}" . ($cr['rotate'] ? " rotated {$cr['rotate']}" : '') . "\n";
            }
        }
        $t .= "\n";
    }
    $t .= "Stripe payment: " . ($s['payment_intent'] ?? '') . "\nCheckout session: " . ($s['id'] ?? '') . "\n";

    if (empty($order['order_txt'])) {
        mf_dropbox_put($folder . '/order.txt', $t);
        $order['order_txt'] = true;
        mf_save_order($order);
    }

    // 3. Email Scott
    $link = 'https://www.dropbox.com/home/Apps/' . rawurlencode($cfg['dropbox_app_folder']) .
        str_replace('%2F', '/', rawurlencode($folder));
    if (empty($order['emailed'])) {
        $subject = ($test ? '[TEST] ' : '') . ($pickup ? '[PICKUP] ' : '') . ($needCrop ? '[CROP NEEDED] ' : '') . 'New magnet order #' . $num . ' - ' . $c['name'] . ' - ' . $m . ' magnet' . ($m === 1 ? '' : 's');
        $body = "New order! " . $m . " magnet" . ($m === 1 ? '' : 's') . ", " . mf_money($amountTotal) . " total.\n" .
            ($needCrop ? ">>> " . $needCrop . " photo" . ($needCrop === 1 ? '' : 's') . " for you to crop (see order.txt).\n" : '') . "\n" .
            "Photos and order.txt:\n" . $link . "\n\n" . $t;
        if (!mf_notify($cfg, $subject, $body, $c['email'])) {
            throw new RuntimeException('mail() failed');
        }
        $order['emailed'] = true;
    }

    // 4. Confirmation email to the customer (from Scott, through Google).
    // A failure here is logged, not retried, so it never blocks the order.
    if (empty($order['customer_emailed']) && filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
        if (empty($cfg['smtp_pass']) || empty($cfg['smtp_user'])) {
            mf_log('Customer confirmation skipped for #' . $num . ': smtp not configured');
        } elseif (mf_smtp_send($cfg, $c['email'],
            ($test ? '[TEST] ' : '') . 'Your Magnet Fairy order #' . $num,
            mf_customer_email($order, $c, $num, $pickup, $needCrop, $amountSub, $amountShip, $amountTax, $amountTotal),
            (string) ($cfg['notify_email'] ?? ''))) {
            $order['customer_emailed'] = true;
        } else {
            mf_log('Customer confirmation FAILED for #' . $num . ' to ' . $c['email']);
        }
    }

    $order['paid'] = true;
    $order['finalized'] = date('c');
    $order['customer'] = $c;
    $order['amount_total'] = $amountTotal;
    $order['amount_tax'] = $amountTax;
    $order['dropbox_link'] = $link;
    mf_save_order($order);
    return ['folder' => $folder];
}

function mf_customer_email(array $order, array $c, string $num, bool $pickup, int $needCrop,
    int $sub, int $ship, int $tax, int $total): string
{
    $first = preg_split('/\s+/', trim($c['name']))[0] ?? '';
    $first = ($first === '' || $first === 'Customer') ? 'there' : $first;
    $b = "Hi {$first},\n\n";
    $b .= "Thank you for your order! Your order number is #{$num}.\n\n";
    $b .= "WHAT YOU ORDERED\n";
    foreach ($order['items'] as $it) {
        $b .= "- " . $it['label'] . "  " . mf_money((int) $it['price']) . "\n";
    }
    $b .= "\nMagnets:  " . mf_money($sub) . "\n";
    $b .= ($pickup ? "Pickup:   " : "Shipping: ") . mf_money($ship) . "\n";
    $b .= "Tax:      " . mf_money($tax) . "\n";
    $b .= "Total:    " . mf_money($total) . "\n\n";
    $b .= "WHAT HAPPENS NEXT\n";
    $b .= "I press every magnet by hand here in Cary. Your magnets will be ready within 3 days.\n";
    if ($pickup) {
        $b .= "You chose free local pickup in Cary. I'll email you when they're ready to set up a pickup time.\n";
    } else {
        $b .= "I'll ship them right after that to:\n\n" . $c['name'] . "\n" . mf_address_lines($c['address']) . "\n";
    }
    if ($needCrop > 0) {
        $b .= "\nYou asked me to crop " . ($needCrop === 1 ? "one photo" : $needCrop . " photos") . " for you. I'll take care of that.\n";
    }
    $b .= "\nIf anything about a photo needs a second look, I'll email you before I press it.\n\n";
    $b .= "Questions? Just reply to this email or call (919) 451-7717.\n\n";
    $b .= "Thank you,\nScott Moore\nThe Magnet Fairy\nthemagnetfairy.com\n";
    return $b;
}

function mf_notify(array $cfg, string $subject, string $body, string $replyTo = ''): bool
{
    if (!empty($cfg['mail_log_only'])) {
        mf_log("MAIL to {$cfg['notify_email']}: {$subject}\n{$body}");
        return true;
    }
    if (!empty($cfg['smtp_pass']) && !empty($cfg['smtp_user'])) {
        if (mf_smtp_send($cfg, $cfg['notify_email'], $subject, $body, $replyTo)) {
            return true;
        }
        mf_log('SMTP send failed; trying mail() as a fallback');
    }
    $from = $cfg['mail_from'] ?? 'shop@themagnetfairy.com';
    $headers = [
        'From: The Magnet Fairy Shop <' . $from . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return mail($cfg['notify_email'], $subject, $body, implode("\r\n", $headers), '-f' . $from);
}
