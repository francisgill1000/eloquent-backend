# Eloquent Bookings

Standalone responsive customer PWA for Eloquent Bookings. Vite + React + TypeScript, talking to the Eloquent Bookings Laravel API.

## Setup
```bash
npm install
cp .env.example .env   # set VITE_API_URL (defaults to production)
npm run dev            # http://localhost:5174
```

## Scripts
- `npm run dev` — dev server
- `npm run build` — type-check + production build to `dist/`
- `npm run preview` — preview the production build
- `npm run test` — run the Vitest suite

Design system is ported from the salesagent mobile app; functionality mirrors the `mobile-app/` customer screens.
