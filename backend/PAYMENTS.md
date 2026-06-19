# Payments — Rielpay KHQR

Fitandsleek uses [Rielpay](https://api-rielpay.fitandsleek.online) for Bakong KHQR payments. NBC/Bakong credentials and Cambodia egress live in Rielpay — not in this app.

## Environment

```ini
RIELPAY_API_URL=https://api-rielpay.fitandsleek.online
RIELPAY_API_KEY=your_live_or_test_key
RIELPAY_CURRENCY=KHR
RIELPAY_EXPIRES_IN=300
```

Health check: `GET {RIELPAY_API_URL}/up` → `Application up`

## Flow

1. Checkout with `bakong_khqr` → `POST /api/payments/bakong/create` (storefront) creates payment via Rielpay.
2. Response includes `qr_string` — shown in checkout UI (no local KHQR generation).
3. Client polls `GET /api/payments/bakong/status/{payment}` every 3s until `paid` or `expired`.
4. Backend calls Rielpay `POST /api/v1/payments/check` with `md5`.

## Removed (do not configure)

- `BAKONG_*` env vars, NBC API token, `KHQR_NODE_BINARY`, `bakong-proxy`, NBC webhook route.

## Merchant settings

Bakong account and merchant display name are configured in the Rielpay merchant dashboard (`merchant-rielpay.fitandsleek.online`).

Admin **Payment Settings** still controls whether KHQR is enabled and optional display labels for receipts.
