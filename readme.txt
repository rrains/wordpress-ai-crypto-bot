you prefer=== Bionic Crypto Bots ===
Contributors: rains
Tags: crypto, trading, bots, dashboard, pnl
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Dashboard and REST API interface for your crypto trading bots: latest trades, P&L stats, best performers, and profit wallet destinations.

== Description ==

Bionic Crypto Bots is the reporting layer for your trading bots. The bots keep
their own exchange connections and API secrets — they simply **report** each
closed trade to this plugin, and you get:

* A dashboard with the latest 20 trades (filterable by exchange and bot)
* Profit & Loss numbers: all-time, today, 7-day, 30-day, fees, win rate
* Best performing crypto, best exchange, and best single trade
* A 30-day daily net P&L chart
* A log of profit withdrawals bots report back to you
* A settings page to store **public profit wallet addresses** the bots fetch
  via the API before sending profits

**Security model:** the plugin never holds exchange API keys or private keys.
Bots authenticate to the plugin with a generated API key sent as an
`X-CTB-Key` header. Wallet addresses stored here are public addresses only.

== Installation ==

1. Zip the `bionic-crypto-bots` folder and upload it via
   **Plugins → Add New → Upload Plugin**, then activate.
2. Go to **Crypto Bots → Settings** and generate an API key. Copy it
   immediately — it is shown only once.
3. Add one or more **profit wallets** (label, exchange, asset, network,
   public address). Mark one as the default destination.
4. Point your bots at the API (below).

== Deploying on a Plesk VPS (host.rainsford.net) ==

1. In Plesk, add the domain/subdomain for WordPress (or use
   **WordPress → Install** via the WordPress Toolkit) and make sure it is
   served over HTTPS — a free Let's Encrypt certificate from
   **Hosting Settings → SSL/TLS Certificates** is fine. The bots must call
   the API over HTTPS so the `X-CTB-Key` header is not exposed in cleartext.
2. Under the subscription's **PHP Settings**, pick PHP 8.1+ for the domain.
3. Upload and activate the plugin (either via WP admin as above, or in Plesk:
   **Files** → `httpdocs/wp-content/plugins/`, then activate in WP admin).
4. Verify pretty permalinks so the REST namespace resolves:
   WP admin → Settings → Permalinks → any non-plain option. Then check
   `https://host.rainsford.net/wp-json/bionic-bots/v1/ping` in a browser —
   it should answer `403 {"code":"ctb_forbidden"}` without a key (proving
   the route is live) and `200 {"ok":true,...}` with the key header.
5. If you run bots on the same VPS, no extra firewall work is needed (they
   hit the site over the public URL). If bots run elsewhere, nothing to
   open — REST calls are plain inbound HTTPS.
6. Optional hardening on Plesk (nginx): block direct PHP execution inside
   `wp-content/uploads`, keep `wp-config.php` out of git, and consider
   Plesk Fail2ban on `xmlrpc.php` and `wp-login.php`.
7. If WordPress lives in a subdirectory (e.g. `httpdocs/blog`), the API base
   becomes `https://host.rainsford.net/blog/wp-json/bionic-bots/v1`.

== API reference ==

Base URL: `https://host.rainsford.net/wp-json/bionic-bots/v1`
(on your Plesk VPS; adjust if WordPress is in a subdirectory)
Auth header on every request: `X-CTB-Key: <your key>`

**Ping**

    GET /ping

**Report trades** (single object or a `trades` array; retry-safe via `uuid`)

    POST /trades
    {
      "bot_name": "grid-01",
      "bot_id": "grid-01-btc",
      "exchange": "binance",
      "symbol": "BTC/USDT",
      "side": "sell",
      "qty": 0.05,
      "price": 64000,
      "fee": 3.2,
      "pnl": 125.5,
      "currency": "USDT",
      "opened_at": "2026-09-28T09:00:00Z",
      "closed_at": "2026-09-28T10:00:00Z"
    }

**Get profit destinations**

    GET /wallets?exchange=binance

Returns the default wallet plus exchange-specific wallets. Bots should send
profits to the matching wallet, falling back to `default`.

**Report a profit transfer**

    POST /withdrawals
    {
      "exchange": "binance",
      "asset": "USDT",
      "amount": 250,
      "wallet_address": "T...your...address",
      "txid": "0xabc...",
      "status": "completed"
    }

**JSON summary**

    GET /summary

Quick test with curl:

    curl -H "X-CTB-Key: YOUR_KEY" https://host.rainsford.net/wp-json/bionic-bots/v1/ping

== Shortcode ==

    [ctb_crypto_dashboard]                (admins only)
    [ctb_crypto_dashboard public="yes"]   (public, read-only)
    [ctb_crypto_dashboard trades="10"]

== Changelog ==

= 1.1.4 =
* Change: internal prefix renamed from bcb_ to ctb_ (tables, options,
  CSS classes, nonces) with automatic migration on upgrade — existing
  trades, capital and settings carry over untouched. The API auth
  header is now X-CTB-Key (new keys use the ctbk_ prefix).

= 1.1.3 =
* Change: admin URLs renamed — the dashboard now lives at
  /wp-admin/admin.php?page=crypto-trading-bot (settings page follows
  the same pattern).
* New: optional clean admin URLs — the plugin can write its own
  .htaccess block so /wp-admin/crypto-trading-bot serves the dashboard
  (Settings → Clean admin URLs; nginx hosting uses the provided snippet).
* Fix: heartbeat staleness math after a server timezone change
  (heartbeats store an explicit UTC timestamp).
* New: the Bot Control card shows the trading strategy the bot runs
  (read-only in the free edition).

= 1.1.0 =
* New: heartbeat watchdog — email alert when a bot stops checking in
  (catches maintenance-mode/coming-soon outages within ~45 minutes),
  plus a recovery notice when reporting resumes.
* New: unrealised P&L line on the dashboard's All-time card
  (from the bot heartbeat, for open positions).

= 1.0.0 =
* Initial release: dashboard, P&L stats, best performers, trade ingest API,
  wallets endpoint, withdrawal log, public shortcode.
