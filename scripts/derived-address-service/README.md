# UAPI Realtime Derived Address Service

This local service derives EVM addresses from an `xpub` for each new order.

## Endpoints

- `GET /healthz`
- `POST /v1/derive`

Request body:

```json
{
  "chain": "bsc",
  "xpub": "xpub....",
  "index": 123,
  "path_prefix": "m/44'/60'/0'/0"
}
```

Response:

```json
{
  "ok": true,
  "chain": "bsc",
  "index": 123,
  "address": "0x....",
  "path": "m/44'/60'/0'/0/123"
}
```

## Deploy (BT + PM2)

1. Install Node.js 20 LTS in BT panel.
2. Enter service dir and install deps:
   - `cd /path/to/uapi/scripts/derived-address-service`
   - `npm install`
3. Start with PM2 (bind localhost only):
   - `DERIVED_ADDR_SERVICE_TOKEN='your_strong_token' HOST=127.0.0.1 PORT=8787 pm2 start server.mjs --name uapi-derived-address`
4. Save PM2 startup:
   - `pm2 save`
   - `pm2 startup`
5. Health check:
   - `curl http://127.0.0.1:8787/healthz`

## PHP side env

Set in project `.env`:

- `DERIVED_ADDR_SERVICE_URL=http://127.0.0.1:8787`
- `DERIVED_ADDR_SERVICE_TOKEN=your_strong_token`
- `DERIVED_ADDR_SERVICE_TIMEOUT=5`

Then reload PHP-FPM/Nginx.
