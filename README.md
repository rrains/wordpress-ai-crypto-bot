# WordPress AI Crypto Bot

**Dashboard and REST API interface for your crypto trading bots — built for WordPress.**

WordPress AI Crypto Bot is the reporting layer for your trading bots. Your bots keep
their own exchange connections and API secrets — they simply **report** each
trade to this plugin, and you get a live, private dashboard inside WordPress.

![status](https://img.shields.io/badge/status-v1.0.0--first--release-green)

## Features

- 📊 **Dashboard** — latest 20 trades (filterable by exchange and bot), live
  bot status, and a 30-day daily net P&L chart (pure SVG, no external libraries)
- 💰 **Profit & Loss** — all-time, today, 7-day, 30-day, fees paid, win rate
- 🏆 **Best performers** — best-performing crypto, exchange, and single trade
- 🎛 **Bot Control** — live heartbeat status from your bots, one-click
  pause/resume from wp-admin
- 🔑 **API key management** — hashed storage, revocable, plaintext shown once
- 💸 **Profit wallets** — bots fetch their payout destination via the API
  before sending profits; public addresses only, never private keys
- 📬 **Email alerts** — instant notification on every reported trade and
  withdrawal
- 🔒 **Security-first design** — the plugin never touches exchange credentials;
  bots authenticate with a header key; withdrawal permissions stay OFF

## How it works

```
Bank → Exchange ← your bots (API keys, trading only) → markets
                          |
                          | report trades (HTTPS + API key)
                          ↓
                 WordPress / this plugin   ← dashboard, P&L, alerts
                          |
                          | hands out profit wallet addresses
                          ↓
                   Profit → your wallets
```

**No money ever flows through WordPress.** The plugin is a scoreboard and
address book, nothing more.

## Requirements

- WordPress 6.0+ and PHP 7.4+
- A **[Kraken](https://www.kraken.com) account** — the included reference bot connector trades via Kraken, so you will need a Kraken account to obtain API keys (kraken.com → Settings → API). Create keys with **Query Funds + Create & Modify Orders** permissions only, **withdrawals disabled**, and (recommended) an IP restriction to your bot's server. Other exchanges with public trading APIs can be supported — Kraken is the one wired up today.

### Server / VPS requirements (for the bot side)

The plugin itself runs on any WordPress-capable host. The **reference bot connector** additionally needs a small Linux server (a VPS is ideal):

- **SSH access** with a dedicated (non-root) user — on Plesk servers, grant the user a **full `/bin/bash` shell**, *not* the chrooted variant (chrooted shells lack `php`, `wp-cli`, and cron access)
- **PHP 8.1+ CLI** on the bot server (the connector runs from cron; no web server needed for the bot itself)
- **Cron access** — the strategy runs on an interval (e.g. every 15 minutes); system crontab or Plesk Scheduled Tasks both work
- **Outbound HTTPS** — the bot needs to reach `api.kraken.com` and your WordPress site
- **A stable outbound IP address** — strongly recommended so you can IP-restrict the exchange API key to your bot's server
- **A writable directory outside the web root** for secrets (e.g. `~/.config/bionic/`) — never store API keys inside `httpdocs`/`public_html`
- **HTTPS on the WordPress site** — mandatory, so bot API keys are never sent in cleartext
- Recommended: NTP time sync (Kraken rejects requests with bad nonces), fail2ban on SSH/mail, and scheduled database backups

Known Plesk quirks: the `wp` CLI shim fails with `php: command not found` unless you prefix the PATH (`export PATH=/opt/plesk/php/8.2/bin:$PATH`), and WP-CLI needs a raised memory limit (`php -d memory_limit=512M` on WP 6.5+).

## Installation

1. Download the latest release zip and install via
   *Plugins → Add New → Upload Plugin*, then activate.
2. Go to **Crypto Bots → Settings** and generate an API key (shown once).
3. Add your **profit wallet** addresses (public addresses only) and mark a
   default destination.
4. Point your bots at the API (below).

Requires WordPress 6.0+, PHP 7.4+.

## API reference

Base URL: `https://your-site.com/wp-json/bionic-bots/v1`
Auth header on every request: `X-BCB-Key: <your key>`

### Ping

```
GET /ping
```

### Report trades (single object or `trades` array; retry-safe via `uuid`)

```
POST /trades
{
  "bot_name": "grid-01",
  "exchange": "binance",
  "symbol": "BTC/USDT",
  "side": "sell",
  "qty": 0.05,
  "price": 64000,
  "fee": 3.2,
  "pnl": 125.5,
  "currency": "USDT",
  "closed_at": "2026-10-01T10:00:00Z"
}
```

### Get profit destinations

```
GET /wallets?exchange=binance&asset=BTC
```

Returns the exchange-matched wallet (falling back to the default), so bots
always know where profits should be sent.

### Report a profit transfer

```
POST /withdrawals
{ "exchange": "binance", "asset": "USDT", "amount": 250,
  "wallet_address": "…", "txid": "…", "status": "completed" }
```

### Heartbeat & remote control

```
POST /heartbeat
```

Bots send their status each tick; the response carries any queued dashboard
command (`pause` / `resume`), enabling the one-click Bot Control card.

### JSON summary

```
GET /summary
```

Quick test:

```
curl -H "X-BCB-Key: YOUR_KEY" https://your-site.com/wp-json/bionic-bots/v1/ping
```

## Shortcode

```
[bcb_crypto_dashboard]                (admins only)
[bcb_crypto_dashboard public="yes"]   (public, read-only)
[bcb_crypto_dashboard trades="10"]
```

## Reference bot connector

The repository author runs a working PHP connector + conservative BTC swing
strategy alongside this plugin (see the release notes for the architecture).
A cleaned-up, documented reference implementation is planned for v1.1.

## Security model

- Bots authenticate with `X-BCB-Key`; keys are stored **hashed** (SHA-256)
- **Exchange API secrets never touch WordPress** — create exchange keys with
  trading permission only, **withdrawals disabled**
- Wallet addresses stored here are **public addresses only**
- All endpoints reject unauthenticated requests (403)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
