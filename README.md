# The Magnet Fairy — Placeholder Website

A single-page placeholder/marketing site for The Magnet Fairy, a custom photo
magnet business. Plain HTML/CSS/JS — no build step, no framework, no
dependencies. Built to hold the storefront until a proper shop is ready.

## What's here

```
index.html        Page content/structure
css/styles.css     All styling (colors, type, layout, responsive rules)
js/main.js         Contact form behavior (client-side only, see below)
images/            Drop real photos in here (see "Adding photos" below)
```

## Running it locally

No server or build step needed — just open `index.html` in a browser, or
serve the folder locally:

```bash
python3 -m http.server 8000
```

Then visit `http://localhost:8000`.

## Adding photos

The hero section currently shows a placeholder box (portrait, 3:4) where a
photo of finished magnets should go — e.g. magnets on a fridge.

To swap it in:
1. Add your image to `images/` (e.g. `images/hero.jpg`).
2. In `index.html`, replace the `<figure class="hero-image">...</figure>`
   block with:
   ```html
   <figure class="hero-image">
     <img src="images/hero.jpg" alt="Custom photo magnets on a fridge" style="width:100%;height:100%;object-fit:cover">
   </figure>
   ```

## The contact form

The form on the page (`#contact-form`) currently only **simulates** success
in the browser — when submitted it shows "Thanks! I'll be in touch soon."
but does **not** actually send you an email or store the message anywhere.
That's fine for a placeholder, but before you rely on it for real leads,
wire it up to a real backend. Two easy no-code options that work fine on
static hosting like Ionos:

- **[Formspree](https://formspree.io)** — free tier, just point the form's
  `action` at the endpoint they give you and add `method="POST"`.
- **[Netlify Forms](https://docs.netlify.com/forms/setup/)** — only works if
  you host on Netlify instead of Ionos, so skip this one for your setup.

Either way, update `js/main.js` to let the real submission happen instead of
(or in addition to) the simulated success message.

## Deploying to Ionos

Ionos website hosting typically works over FTP/SFTP or a file manager in
your Ionos control panel:

1. Log into your Ionos hosting control panel.
2. Find the file manager or FTP credentials for your hosting package.
3. Upload the contents of this folder (`index.html`, `css/`, `js/`,
   `images/`) to your site's web root (often `htdocs` or similar — check
   your Ionos hosting docs, as it varies by plan).
4. Visit your domain to confirm it's live.

## Design source

This site was built from a high-fidelity design handoff (colors, type,
spacing, and copy are final per that handoff). Design tokens live at the
top of `css/styles.css` as CSS custom properties if you need to tweak
colors or spacing later.
