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
    'dropbox_refresh_token' => '...',
    'dropbox_app_folder'    => 'Magnet Fairy Shop',   // the app's folder name under Dropbox/Apps/

    'notify_email' => 'scott@themagnetfairy.com',
    'mail_from'    => 'shop@themagnetfairy.com',
    'site_url'     => 'https://themagnetfairy.com',

    // 'stripe_tax' = Stripe Tax figures NC tax by county (and on shipping). 'none' = no tax.
    'tax_mode'     => 'stripe_tax',
    'rate_salt'    => 'any-random-words-here',
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
4. Get a refresh token: open
   `https://www.dropbox.com/oauth2/authorize?client_id=APP_KEY&response_type=code&token_access_type=offline`,
   click Allow, copy the code, then exchange it:
   ```
   curl https://api.dropboxapi.com/oauth2/token \
     -d code=THE_CODE -d grant_type=authorization_code \
     -u APP_KEY:APP_SECRET
   ```
   Put the `refresh_token` from the reply in the config.

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
