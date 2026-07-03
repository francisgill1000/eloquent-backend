# WhatsApp Chats in admin — Design

Date: 2026-06-06
Status: Approved (Francis: "i trust you. just do it")

## Goal

Port the chat functionality from the standalone `whatsapp-autoreply` Node app into the
AI Business Manager stack: Laravel backend (`AI Business Manager/backend`) + admin PWA. Multi-account: each shop
connects its own WhatsApp Business number. Chat only — no Claude auto-reply for now.

## Architecture

- WhatsApp Cloud API webhooks hit `POST /api/wa/webhook` on `api.eloquentservice.com`.
- Routing key: `entry[].changes[].value.metadata.phone_number_id` → `wa_accounts.phone_number_id`.
- Consequence: the Meta app's callback URL moves from the Node app to Laravel; the old
  Node dashboard stops receiving messages (accepted).
- Live updates via polling (thread ~4s with `since_id`, contact list ~10s). No SSE.

## Backend

### Tables

- `wa_accounts`: id, shop_id (unique FK), phone_number, phone_number_id (unique),
  waba_id (nullable), token (encrypted cast), status (default `active`), timestamps.
- `wa_contacts`: id, wa_account_id FK, wa_number, name (nullable), last_message_at,
  unread_count (default 0), timestamps. Unique (wa_account_id, wa_number).
- `wa_messages`: id, wa_account_id, wa_contact_id, direction (`in`/`out`), type
  (default `text`), body, wa_message_id (nullable), status (nullable), timestamps.
  Index (wa_contact_id, id).

### Endpoints

Public:
- `GET /api/wa/webhook` — Meta verify handshake (`WHATSAPP_VERIFY_TOKEN` env).
- `POST /api/wa/webhook` — receive; find account by phone_number_id, upsert contact
  (name from profile), store `in` message, bump unread + last_message_at. Always 200.

Authed (`auth:sanctum`, shop = `$request->user()`):
- `GET /shop/wa/account` — `{ connected, phone_number, phone_number_id, waba_id, token_preview }`.
- `POST /shop/wa/account` — create/update credentials. Token write-only; empty token on
  update keeps existing.
- `GET /shop/wa/contacts` — ordered by last_message_at desc, with last message preview.
- `GET /shop/wa/contacts/{contact}/messages?since_id=` — ascending, ownership-checked.
- `POST /shop/wa/contacts/{contact}/messages` — send via Graph API
  (`graph.facebook.com/v25.0/{phone_number_id}/messages`), store `out` message.
  Graph errors → 422 with message.
- `POST /shop/wa/contacts/{contact}/read` — clear unread.

### Code

- Models: `WaAccount` (encrypted token cast), `WaContact`, `WaMessage`.
- Service: `App\Services\WhatsAppCloud` — `sendText(WaAccount, to, text)`,
  `parseIncoming(payload)`.
- Controllers: `WaWebhookController`, `WaChatController`.
- Ownership checks via `abort_unless(...)` matching existing controller style.

## Frontend (admin)

- Nav: 6th bottom tab `Chats` (chat-bubble icon) in `MobileLayout.tsx`.
- `src/lib/chats.ts` — API module; types in `types.ts` (`WaAccountInfo`, `WaContact`, `WaMessage`).
- `Chats.tsx` (tab page): contact list — search, avatar initials, name/number, last
  message preview ("You: " prefix for out), relative time, mint unread badge. If not
  connected → empty state with "Set up WhatsApp" CTA.
- `ChatThread.tsx` (full-screen `/chats/:id`): bubbles in/left/dark, out/right/mint,
  timestamps, auto-scroll, composer + 24h-window hint, 4s polling with since_id,
  marks read on open.
- `WhatsAppSetup.tsx` (`/chats/setup`): phone number, Phone Number ID, WABA ID,
  Access Token, Save + "Skip for now". Linked from Chats empty state and Profile.
- First-login prompt: after login, if account not connected and localStorage flag
  `wa_setup_skipped` unset → redirect once to `/chats/setup`.
- Co-located vitest tests for new pages.

## Deploy

1. Backend: push + `php artisan migrate` on droplet (deploy-eloquent-app flow).
2. admin: `deploy.ps1` static SPA deploy.
3. Manual (Francis): point Meta app webhook to
   `https://api.eloquentservice.com/api/wa/webhook` with the verify token.
