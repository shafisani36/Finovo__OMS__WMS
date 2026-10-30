# Finovo OMS / WMS

Order and warehouse management for companies that sell on several online stores and ship from one or more
warehouses. Orders, products and stock are imported from the stores (Shopify, WooCommerce, BigCommerce,
PrestaShop, Wix, eBay, Magento, OpenCart, osCommerce, custom bridge, or entered by hand), allocated to a
warehouse, picked, packed and dispatched with a courier, and the result is sent back to the store.

## Structure

| Folder | What it is |
|---|---|
| `backend/` | Laravel 12 API (`/api/v1`), database migrations, store and courier integrations, tests |
| `Frontend/` | React + Vite + TypeScript app (desktop screens and the `/mobile` scanner app) |

## Run locally

Backend (port 8001, with queue worker, scheduler and logs):

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer dev
```

Frontend (proxies `/api` to the backend on port 8001):

```bash
cd Frontend
npm install
npm run dev
```

On Windows, `start-dev.bat` starts both.

## Tests and checks

```bash
cd backend && php artisan test
cd Frontend && npm run build && npm run lint
```

## Store connections that call back

Approving a store app (Wix, Shopify, BigCommerce, eBay), the PrestaShop and OpenCart modules, and instant
import (webhooks) need Finovo on a public HTTPS address. Set `APP_URL` and `WEBHOOK_BASE_URL` in
`backend/.env` to that address (for local testing, a tunnel such as `cloudflared tunnel --url http://127.0.0.1:8001`).

`backend/.env` holds secrets and is not committed; `backend/.env.example` lists every setting.
