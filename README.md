# The Magnet Fairy — Placeholder Website

A single-page placeholder/marketing site for The Magnet Fairy, a custom photo
magnet business. Plain HTML/CSS/JS — no build step, no framework, no
dependencies. Built to hold the storefront until a proper shop is ready.

## What's here

```
index.html        Page content/structure
css/styles.css     All styling (colors, type, layout, responsive rules)
js/main.js         Contact form behavior (submits to Formspree, see below)
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

The hero section shows a real photo (`images/hero.jpg`, portrait 3:4). To
swap it for a different one later:
1. Add the new image to `images/` (e.g. `images/hero.jpg`, replacing the
   existing file, or a new filename of your choice).
2. If you used a new filename, update the `src` on the `<img>` inside
   `<figure class="hero-image">` in `index.html` to match.

## The contact form

The form (`#contact-form`) submits to
**[Formspree](https://formspree.io)** (`https://formspree.io/f/xnpallke`) —
submissions get emailed to whatever address is configured on that Formspree
form. `js/main.js` submits it via `fetch` so the page never reloads: on
success it shows "Thanks! I'll be in touch soon.", on failure it shows an
error message and lets the visitor try again.

Notes:
- The free Formspree tier caps at 50 submissions/month. If you outgrow it,
  upgrade the plan or switch endpoints — no code changes needed beyond the
  `action` URL in `index.html`.
- To change which inbox gets the messages, or to see submission history, log
  into your Formspree dashboard at formspree.io.

## Deployment

Live at **themagnetfairy.com**, hosted on Bluehost (Starter plan, 3-year
term, purchased Aug 2026). Deployment is git-based: `public_html` on the
server *is* a git working tree, reachable over SSH.

To deploy a change:

```bash
./deploy.sh
```

This pushes `main` to both `origin` (GitHub, source of truth/history) and
`bluehost` (the live server) — the Bluehost push updates `public_html`
in place immediately, no manual file upload needed. Equivalent to running:

```bash
git push origin main
git push bluehost main
```

**How it's wired up**, in case this needs to be reproduced on a new machine:
- SSH access is enabled on the Bluehost account (Hosting → Files & Access →
  SSH), with a dedicated deploy key (`~/.ssh/id_ed25519_bluehost` locally).
- `~/.ssh/config` has a `bluehost-magnetfairy` host alias pointing at the
  server IP with that key.
- The `bluehost` git remote is `ssh://bluehost-magnetfairy/home1/fydicimy/public_html`.
- On the server, `public_html` was `git init`'d in place (not cloned fresh —
  it already had live files in it) with
  `git config receive.denyCurrentBranch updateInstead`, which makes a
  direct push update the checked-out working tree immediately, no manual
  `git pull` or webhook needed.
- **`.htaccess` and `.well-known/` are tracked in this repo too** —
  don't delete them. They hold Bluehost/Let's Encrypt SSL verification
  files and server config; removing them from a commit would delete them
  from the live server on the next push.

Bluehost account/support: my.bluehost.com (account ID 154350322).

## Design source

This site was built from a high-fidelity design handoff (colors, type,
spacing, and copy are final per that handoff). Design tokens live at the
top of `css/styles.css` as CSS custom properties if you need to tweak
colors or spacing later.
