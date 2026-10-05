# WordPress AI Crypto Bot

**Dashboard and REST API interface for your crypto trading bots — built for WordPress.**

WordPress AI Crypto Bot is the reporting layer for your trading bots. Your bots keep
their own exchange connections and API secrets — they simply **report** each
trade to this plugin, and you get a live, private dashboard inside WordPress.

![status](https://img.shields.io/badge/status-v1.1.3-green)

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

### PHP memory requirements

Modern WordPress (6.5+) with WooCommerce and several plugins routinely exceeds the default **128M** PHP memory limit, causing random `Allowed memory size exhausted` fatal errors — especially in `wp-admin` and WP Toolkit's single-sign-on login.

Fix it either way (both is best):

1. **Hosting-level**: set `memory_limit` to `512M` in your hosting panel's PHP settings (Plesk: *Websites & Domains → PHP Settings → memory_limit*).
2. **WordPress-level**: add to `wp-config.php` *before* the “stop editing” comment:

```php
define( 'WP_MEMORY_LIMIT', '512M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );
```

WordPress can raise its own memory limit at runtime (PHP allows `memory_limit` to be increased but not decreased), so the `wp-config.php` constants work even if your host caps the php.ini value at 128M. `WP_MAX_MEMORY_LIMIT` specifically governs `wp-admin` requests, which are the heaviest.

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
Auth header on every request: `X-CTB-Key: <your key>`

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
curl -H "X-CTB-Key: YOUR_KEY" https://your-site.com/wp-json/bionic-bots/v1/ping
```

## Shortcode

```
[ctb_crypto_dashboard]                (admins only)
[ctb_crypto_dashboard public="yes"]   (public, read-only)
[ctb_crypto_dashboard trades="10"]
```

## Reference bot connector

The repository author runs a working PHP connector + conservative BTC swing
strategy alongside this plugin (see the release notes for the architecture).
A cleaned-up, documented reference implementation is planned for v1.1.

## Security model

- Bots authenticate with `X-CTB-Key`; keys are stored **hashed** (SHA-256)
- **Exchange API secrets never touch WordPress** — create exchange keys with
  trading permission only, **withdrawals disabled**
- Wallet addresses stored here are **public addresses only**
- All endpoints reject unauthenticated requests (403)

## Roadmap

The current release (v1.x) is the foundation. The **Pro** edition builds on it in phases:

- [ ] **Pro 0 — Licensing & activation** — per-site license keys, so Pro installations can be sold and activated
- [ ] **Pro 1 — Multi-bot dashboard** — bot registry (up to 10 to start), one dashboard tab per registered bot, per-bot API keys, symbol filter on all trade tables
- [ ] **Pro 2 — Exchange connector framework** — a common connector interface (`balance`, `ticker`, `buy`, `sell`) with Kraken as the reference implementation, plus new connectors (Binance, Bybit, OKX, Coinbase Advanced)
- [ ] **Pro 3 — Multi-pair strategies** — run the same strategy across multiple pairs (BTC/USDC, XRP/USDT, SOL/USDT, ETH/USDT, DOGE/USDT, LTC/USDT …), each with its own position state
- [ ] **Pro 4 — Capital tracking & true ROI** — record deposits, compute real return-on-capital per bot and per exchange
- [ ] **Pro 5 — Notification suite** — Telegram digests, stale-heartbeat alerts as standard, configurable thresholds

*Note on pairs: availability depends on the exchange — e.g. XMR/USDT has been delisted from several major exchanges, so pair support is checked per exchange before release.*

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
