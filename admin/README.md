# Admin — Service Provider Web App

Provider-facing PWA for Admin shops. Vite + React + TypeScript, talking to the Admin
Laravel API. Mirrors the `eloquent-bookings` stack/design; functionality is ported from the
`mobile-app/` shop screens.

Live: **https://admin.eloquentservice.com**

## Setup
```bash
npm install
cp .env.example .env   # set VITE_API_URL (defaults to production)
npm run dev            # http://localhost:5175
```

## Scripts
- `npm run dev` — dev server (port 5175)
- `npm run build` — type-check + production build to `dist/`
- `npm run preview` — preview the production build
- `npm run test` — run the Vitest suite
- `npm run icons` — regenerate PWA icons from `public/favicon.svg`

## Features
Shop login (shop_code + PIN), registration, forgot-PIN, dashboard (KPIs + upcoming
bookings), bookings list, booking actions (confirm/cancel/complete, staff reassign,
mark invoice paid), reminders (WhatsApp nudges), services CRUD, staff management,
working hours, business profile, and QR desktop-login approval (`/scan/:token`).

## Auth
Two-step shop login → `POST shops/login`. Token persisted in `localStorage` as
`shop_token` / `shop_data` (distinct from the customer app's `customer_token`).
"Remember me" persists credentials; no biometric (web).

## Deployment
Static SPA served by nginx on the shared droplet (`64.227.153.90`) at
`admin.eloquentservice.com`, same model as `eloquent-bookings` (served at
`bookings.eloquentservice.com`). Run `./deploy.ps1` to build + upload `dist/` and chown to
`www-data`. The nginx server block (`/etc/nginx/sites-available/admin`) has an SPA
fallback (`try_files $uri /index.html`) and a Certbot-managed TLS cert.
