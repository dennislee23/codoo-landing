# Codoo — product landing (codoo.kittykat.tech)

Static landing for **Codoo** — an AI WhatsApp guest assistant for short-stay
rental operators (the productized version of the Roland bot, for selling to
other operators). "Codoo by KittyKat".

> Note: the repo is named `roland-landing` for historical reasons; the product
> is **Codoo**. Repo name kept on purpose.

## What's here
- `index.html` — the whole landing (one file, inline CSS/JS). Apple-style.
  Bricolage Grotesque + Hanken Grotesk, blue→green gradient. WhatsApp phone
  mockups are drawn in HTML/CSS (no image assets). Copy is deliberately plain
  (no marketing fluff). Live case is anonymised ("a 30-apartment operation in
  Tallinn" — do not expose the client name).
- `ask.php` — backend for the **"Ask Codoo"** on-page assistant. PHP →
  Anthropic `/v1/messages` (claude-sonnet-4-5). Per-IP rate limit, length caps,
  CORS. Reads `ANTHROPIC_API_KEY` from `.env`. The system prompt (in this file)
  teaches the model everything about Codoo.
- `update.html` + `deploy.php` — on-demand "Update Now" deploy (pull this repo
  into the web root). Password-gated by `DEPLOY_KEY` in `.env`.
- `.htaccess` — blocks `.env`, noindexes `update.html`, short-caches HTML.

## "Ask Codoo" widget
Centered command-palette modal (like the kittykat.tech advisor), opened from the
nav "Ask Codoo" button or **⌘K / Ctrl+K**; Esc / click-outside closes. Not a
corner chat bubble.

## Hosting / deploy
- Host: zone.ee, kittykat.tech account. Web root `~/domeenid/www.kittykat.tech/booking`
  is a git clone of this repo. SSH `virt105026@kittykat.tech` (key `~/.ssh/id_ed25519`).
- `.env` on the server holds `DEPLOY_KEY` and `ANTHROPIC_API_KEY` (same key as the
  kittykat.tech advisor). **Never commit `.env`** (gitignored).
- To deploy: `git push origin main`, then open `https://codoo.kittykat.tech/update.html`,
  enter the deploy key, "Update Now".

## TODO
- favicon + OG image (Codoo logo)
- DE / RU / FR translations
- optional: dedicated demo WhatsApp number / contact (currently +372 5382 9955)
