# Online shop: setup and go-live

The shop lives at **themagnetfairy.com/shop/**. It is plain HTML/JS plus three
small PHP files in `api/`. No secrets are in this repo.

```
shop/index.html        Shop page (products, photo slots, cropper, cart)
shop/thanks.html       Page customers land on after paying
shop/products.json     Prices, pack sizes, paper cut sizes  <- edit prices here
js/shop.js             Shop behavior
css/shop.css           Shop styles
js/vendor, css/vendor  Cropper.js 1.6.2 (MIT)
api/get-upload-link.php   Gives the browser a one-time Dropbox upload link
api/create-checkout.php   Checks prices on the server, opens Stripe Checkout
api/stripe-webhook.php    On payment: files the order in Dropbox, emails Scott
api/dropbox-connect.php   One-time: connects the shop to the Dropbox app
api/_lib.php              Shared helpers (blocked from the web)
```

## How an order flows

1. Customer picks a size and pack, then a photo for each magnet.
2. The **original** photo uploads straight from their phone to Dropbox
   (`Apps/<app folder>/Pending/<cart id>/`). Nothing is shrunk.
3. They crop. A **print-ready** JPEG (paper cut size, 300 dpi) uploads too.
4. Checkout: `create-checkout.php` re-prices the cart from `products.json`,
   confirms every photo is in Dropbox, and sends them to Stripe Checkout.
5. Stripe calls `stripe-webhook.php` when the payment succeeds. It moves the
   folder to `Orders/YYYY-MM-DD Lastname #ABC123`, writes `order.txt`, and
   emails Scott the Dropbox link. Stripe emails the customer a receipt.

## The private config file (outside public_html)

Create this file on Bluehost (File Manager, or SSH). It must NOT be inside
`public_html`:

```
/home1/fydicimy/magnet-fairy-private/config.php
```

```php
<?php
return [
    // Stripe: Dashboard > Developers > API keys. Start with the TEST key (sk_test_...).
    'stripe_secret_key'     => 'sk_test_...',
    // Stripe: Dashboard > Developers > Webhooks > the themagnetfairy.com endpoint > Signing secret.
    'stripe_webhook_secret' => 'whsec_...',

    // Dropbox app (see below)
    'dropbox_app_key'       => '...',
    'dropbox_app_secret'    => '...',
    'dropbox_app_folder'    => 'Magnet Fairy Shop',   // the app's folder name under Dropbox/Apps/

    'notify_email' => 'scott@themagnetfairy.com',
    'mail_from'    => 'shop@themagnetfairy.com',
    // Order emails go through Google (Bluehost drops mail to our own domain).
    // smtp_pass = a Google app password for smtp_user (spaces are fine).
    'smtp_user'    => 'scott@themagnetfairy.com',
    'smtp_pass'    => '',
    'site_url'     => 'https://themagnetfairy.com',

    // 'stripe_tax' = Stripe Tax figures NC tax by county (and on shipping). 'none' = no tax.
    'tax_mode'     => 'stripe_tax',
    'rate_salt'    => 'any-random-words-here',
    'setup_key'    => 'another-random-phrase',   // only used by api/dropbox-connect.php
];
```

Then lock it down: `chmod 600 config.php`. The API creates `orders/`, `logs/`
and `ratelimit/` folders next to it. If something goes wrong, check
`magnet-fairy-private/logs/`.

## Dropbox app (one time)

1. dropbox.com/developers/apps > Create app > Scoped access > **App folder**.
   Name: `Magnet Fairy Shop` (Dropbox may require a unique name).
2. Permissions tab: tick `files.metadata.read`, `files.content.write`,
   `files.content.read`. Submit.
3. Settings tab: copy the App key and App secret into the config.
4. Settings tab > OAuth 2 > Redirect URIs: add
   `https://themagnetfairy.com/api/dropbox-connect.php`.
5. After deploying, open
   `https://themagnetfairy.com/api/dropbox-connect.php?key=SETUP_KEY`
   (SETUP_KEY = `setup_key` in the config) and click Allow. The refresh token
   is saved to `magnet-fairy-private/dropbox-refresh.json` automatically.

## Stripe (one time)

- Test-mode webhook already exists: `https://themagnetfairy.com/api/stripe-webhook.php`
  for `checkout.session.completed` and `checkout.session.async_payment_succeeded`.
- Settings > Customer emails: turn on **Successful payments** so customers get receipts.
- Tax: test mode has a Cary head office and an NC registration. For live mode,
  add the NC registration only after the NC sales tax registration is approved.

## Testing (test mode)

Card `4242 4242 4242 4242`, any future date, any CVC, any ZIP.
Check: Dropbox folder moved and named, `order.txt` is right, the email arrived.

## Going live

1. In Stripe, switch to live mode. Create the same webhook (live) and add the NC
   tax registration.
2. In the config, swap in the live `sk_live_...` key and the live webhook's `whsec_...`.
3. Place one real order, then refund it from the Stripe Dashboard.

## Changing prices

Edit `shop/products.json` (amounts are in cents) and deploy. The page and the
server both read that file, so they always agree.


## Order email (fixed 2026-11-02)
Bluehost's mail server treats themagnetfairy.com as a local domain and silently
drops mail to scott@ even with Email Routing set to Remote. Order emails are sent
through Gmail SMTP (smtp.gmail.com:465) with a Google app password in `smtp_pass`.
Test from cPanel Terminal: `php ~/public_html/api/_mail-test.php`
