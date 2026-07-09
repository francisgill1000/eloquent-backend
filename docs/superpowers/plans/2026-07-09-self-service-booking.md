# Self-Service Booking Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A public, no-auth booking page inside the admin app where a shop's customers book themselves by voice (assistant fills the form live) or by tapping the form; it creates a real confirmed booking.

**Architecture:** Reuse existing public endpoints (`GET /shops/{shop}` for services/hours, `POST /shops/{shop}/book` to create). Add one new *customer-scoped* Claude field-extraction assistant (`POST /shops/{shop}/book-assistant/{text,voice}`) that can ONLY read services and set booking fields. Frontend is a single React page combining a voice-reactive mic and a live-filling form sharing one state object; TTS of replies is done client-side via the existing `/tts` helper.

**Tech Stack:** Laravel (PHP 8.4) + Sanctum-free public routes; React + Vite + TypeScript; axios (`@/lib/api`, auto-injects `X-Device-Id`); vitest + @testing-library/react; Anthropic Messages API via `App\Services\Wa\ClaudeClient`; OpenAI Whisper via `App\Services\Wa\Transcriber`; OpenAI TTS via `/tts`.

## Global Constraints

- **Multi-tenant:** never hardcode a shop's name/brand; everything derives from the `{shop}` in the route.
- **PHP tests run on the droplet only** (php8.4), never local, never against the prod DB (use `RefreshDatabase` on the scratch/test DB).
- **Public endpoints require `X-Device-Id`** — the admin axios instance injects it automatically; the create endpoint (`BookSlotRequest`) rejects requests without it (422).
- **Do NOT reference the old customer app** (`bookings.eloquentservice.com`) for design; reuse this admin app's classes (`c-*`, `va-*`, `Icons`, mint glass tokens).
- **Voice assistant is field-extraction only** — no revenue, cancellations, customer data, or owner tools.
- Work directly on `main`; commit frequently. No feature branches.

---

### Task 1: Backend — customer booking assistant (field extraction)

**Files:**
- Create: `app/Support/Assistant/PublicBookingPrompt.php`
- Create: `app/Http/Controllers/PublicBookingAssistantController.php`
- Modify: `routes/api.php` (add two public routes near the other public AI routes, ~line 150)
- Test: `tests/Feature/PublicBookingAssistantTest.php`

**Interfaces:**
- Consumes: `App\Services\Wa\ClaudeClient::agentReply(string $system, array $history, array $tools): array{text:string, toolUse:array{name:string,input:array}|null}`; `App\Services\Wa\Transcriber::transcribe(string $bytes, string $mime): ?string`; `App\Models\Shop` with `catalogs` relation (`title`, `price`).
- Produces: `POST /api/shops/{shop}/book-assistant/text` and `/voice` returning JSON `{ transcript?:string, reply_text:string, fields:object, ready:bool }`. `fields` keys are a subset of `service, date, start_time, customer_name, customer_phone`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicBookingAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        $shop = Shop::create(['name' => 'FreshPress', 'shop_code' => '1001', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $shop->catalogs()->create(['title' => 'Classic Haircut', 'price' => 30]);
        return $shop;
    }

    private array $headers = ['X-Device-Id' => 'dev-123'];

    public function test_text_extracts_booking_fields_and_needs_no_auth(): void
    {
        $shop = $this->shop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [
                ['type' => 'text', 'text' => 'Great — what day works?'],
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'set_booking',
                 'input' => ['service' => 'Classic Haircut', 'customer_name' => 'Sara']],
            ]]),
        ]);

        $res = $this->postJson("/api/shops/{$shop->id}/book-assistant/text",
            ['text' => 'I want a classic haircut, my name is Sara', 'state' => []], $this->headers);

        $res->assertCreated()
            ->assertJsonPath('reply_text', 'Great — what day works?')
            ->assertJsonPath('fields.service', 'Classic Haircut')
            ->assertJsonPath('fields.customer_name', 'Sara')
            ->assertJsonPath('ready', false);
    }

    public function test_ready_true_when_model_sets_it(): void
    {
        $shop = $this->shop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [
                ['type' => 'text', 'text' => 'All set — tap confirm.'],
                ['type' => 'tool_use', 'id' => 't2', 'name' => 'set_booking',
                 'input' => ['service' => 'Classic Haircut', 'date' => '2026-07-12', 'start_time' => '15:00',
                             'customer_name' => 'Sara', 'customer_phone' => '0501234567', 'ready' => true]],
            ]]),
        ]);

        $res = $this->postJson("/api/shops/{$shop->id}/book-assistant/text",
            ['text' => 'friday 3pm, 0501234567', 'state' => ['service' => 'Classic Haircut']], $this->headers);

        $res->assertCreated()->assertJsonPath('ready', true)
            ->assertJsonPath('fields.start_time', '15:00');
    }

    public function test_empty_model_reply_falls_back_to_a_question(): void
    {
        $shop = $this->shop();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [
                ['type' => 'tool_use', 'id' => 't3', 'name' => 'set_booking', 'input' => ['service' => 'Classic Haircut']],
            ]]),
        ]);

        $res = $this->postJson("/api/shops/{$shop->id}/book-assistant/text",
            ['text' => 'a haircut', 'state' => []], $this->headers);

        $res->assertCreated();
        $this->assertNotEmpty($res->json('reply_text'));
    }

    public function test_voice_transcribes_then_extracts(): void
    {
        $shop = $this->shop();
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'classic haircut please']),
            'api.anthropic.com/*' => Http::response(['content' => [
                ['type' => 'text', 'text' => 'Sure — what day?'],
                ['type' => 'tool_use', 'id' => 't4', 'name' => 'set_booking', 'input' => ['service' => 'Classic Haircut']],
            ]]),
        ]);

        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'BYTES', 'audio/webm');
        $res = $this->post("/api/shops/{$shop->id}/book-assistant/voice",
            ['audio' => $audio, 'state' => json_encode([])], $this->headers);

        $res->assertCreated()
            ->assertJsonPath('transcript', 'classic haircut please')
            ->assertJsonPath('fields.service', 'Classic Haircut');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run (on the droplet): `php artisan test --filter=PublicBookingAssistantTest`
Expected: FAIL — route/controller do not exist (404 / class not found).

- [ ] **Step 3: Create the prompt builder**

`app/Support/Assistant/PublicBookingPrompt.php`:

```php
<?php
namespace App\Support\Assistant;

use App\Models\Shop;

class PublicBookingPrompt
{
    /** @param array<string,mixed> $state fields already collected */
    public static function for(Shop $shop, array $state): string
    {
        $services = collect($shop->catalogs ?? [])
            ->map(fn ($c) => '- ' . $c['title'] . ' (AED ' . $c['price'] . ')')
            ->implode("\n") ?: '- (ask the customer what they need)';

        $today = now()->toDateString();
        $known = collect($state)->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v, $k) => "$k: $v")->implode(', ') ?: 'nothing yet';

        return <<<TXT
You are the booking assistant for "{$shop->name}". Your ONLY job is to help this
customer book one appointment. Never discuss anything else — no other businesses,
no owner or account topics, no data beyond the service list below.

Services offered:
{$services}

Today is {$today}. Collect these five details: service, date (YYYY-MM-DD),
start_time (24-hour HH:MM), the customer's name, and their phone number. Ask only
for what is still missing, one friendly question at a time, in the customer's own
language. Keep every reply to a single short sentence. Whenever the customer gives
or changes any detail, call the set_booking tool with those fields. Set ready=true
only once all five details are known.

Known so far: {$known}.
TXT;
    }
}
```

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/PublicBookingAssistantController.php`:

```php
<?php
namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\Wa\ClaudeClient;
use App\Services\Wa\Transcriber;
use App\Support\Assistant\PublicBookingPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public, customer-facing booking assistant. No auth: a customer opens the
 * shop's booking link and speaks/types their request. Deliberately minimal —
 * it can ONLY read the shop's services and fill booking fields. It never
 * touches revenue, other bookings, or any owner tool.
 */
class PublicBookingAssistantController extends Controller
{
    public function __construct(
        protected ClaudeClient $claude,
        protected Transcriber $transcriber,
    ) {}

    public function text(Request $request, Shop $shop): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:1000']]);
        return $this->respond($shop, $data['text'], null, $this->readState($request));
    }

    public function voice(Request $request, Shop $shop): JsonResponse
    {
        $request->validate([
            'audio' => ['required', 'file', 'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/mpeg,audio/m4a,audio/wav,video/webm', 'max:25600'],
        ]);

        $file = $request->file('audio');
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = $file->getMimeType() ?: 'audio/webm';

        $transcript = null;
        try {
            $transcript = $this->transcriber->transcribe($bytes, $mime);
        } catch (\Throwable $e) {
            Log::warning('public booking transcription failed: ' . $e->getMessage());
        }

        if (! $transcript) {
            return response()->json([
                'transcript' => '',
                'reply_text' => "Sorry, I didn't catch that — please try again.",
                'fields' => (object) [],
                'ready' => false,
            ], 201);
        }

        return $this->respond($shop, $transcript, $transcript, $this->readState($request));
    }

    /** @param array<string,mixed> $state */
    protected function respond(Shop $shop, string $text, ?string $transcript, array $state): JsonResponse
    {
        $shop->loadMissing('catalogs');
        $system = PublicBookingPrompt::for($shop, $state);

        $fields = [];
        $ready = false;
        $reply = '';
        try {
            $res = $this->claude->agentReply($system, [['role' => 'user', 'content' => $text]], [$this->setBookingTool()]);
            $reply = $res['text'];
            if ($res['toolUse'] && $res['toolUse']['name'] === 'set_booking') {
                $input = $res['toolUse']['input'];
                $ready = (bool) ($input['ready'] ?? false);
                unset($input['ready']);
                $allowed = ['service', 'date', 'start_time', 'customer_name', 'customer_phone'];
                $fields = collect($input)
                    ->only($allowed)
                    ->filter(fn ($v) => $v !== null && $v !== '')
                    ->all();
            }
        } catch (\Throwable $e) {
            Log::warning('public booking assistant failed: ' . $e->getMessage());
        }

        if (trim($reply) === '') {
            $reply = $this->fallbackReply(array_merge($state, $fields));
        }

        $payload = ['reply_text' => $reply, 'fields' => (object) $fields, 'ready' => $ready];
        if ($transcript !== null) {
            $payload['transcript'] = $transcript;
        }
        return response()->json($payload, 201);
    }

    /** @param array<string,mixed> $f */
    private function fallbackReply(array $f): string
    {
        $asks = [
            'service' => 'Which service would you like?',
            'date' => 'What day works for you?',
            'start_time' => 'What time would you like?',
            'customer_name' => 'And your name?',
            'customer_phone' => "What's the best number to reach you?",
        ];
        foreach ($asks as $key => $question) {
            if (empty($f[$key])) {
                return $question;
            }
        }
        return 'Great — tap Confirm to book.';
    }

    private function readState(Request $request): array
    {
        $state = $request->input('state', []);
        if (is_string($state)) {
            $state = json_decode($state, true);
        }
        return is_array($state) ? $state : [];
    }

    private function setBookingTool(): array
    {
        return [
            'name' => 'set_booking',
            'description' => 'Record the booking details the customer has given so far. Call whenever any detail is provided or changed.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string', 'description' => 'Service title, matching one the shop offers.'],
                    'date' => ['type' => 'string', 'description' => 'Booking date, YYYY-MM-DD.'],
                    'start_time' => ['type' => 'string', 'description' => '24-hour start time, HH:MM.'],
                    'customer_name' => ['type' => 'string'],
                    'customer_phone' => ['type' => 'string', 'description' => 'Customer phone / WhatsApp number.'],
                    'ready' => ['type' => 'boolean', 'description' => 'True only when all five details are known.'],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/api.php`, after the `/ai/categories` block (~line 150), add:

```php
// Public customer booking assistant — field extraction only (no auth, no owner
// tools). Keyed by X-Device-Id; throttled since each hits Claude/Whisper.
Route::post('/shops/{shop}/book-assistant/text',  [\App\Http\Controllers\PublicBookingAssistantController::class, 'text'])
    ->middleware('throttle:20,1');
Route::post('/shops/{shop}/book-assistant/voice', [\App\Http\Controllers\PublicBookingAssistantController::class, 'voice'])
    ->middleware('throttle:20,1');
```

- [ ] **Step 6: Run the tests to verify they pass**

Run (on the droplet): `php artisan test --filter=PublicBookingAssistantTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Support/Assistant/PublicBookingPrompt.php app/Http/Controllers/PublicBookingAssistantController.php routes/api.php tests/Feature/PublicBookingAssistantTest.php
git commit -m "feat(booking): public customer booking assistant (field extraction)"
```

---

### Task 2: Frontend — data/lib layer

**Files:**
- Create: `admin/src/lib/publicBooking.ts`
- Test: `admin/src/lib/publicBooking.test.ts`

**Interfaces:**
- Consumes: `@/lib/api` (default axios instance).
- Produces:
  - `type BookingFields = { service?: string; date?: string; start_time?: string; customer_name?: string; customer_phone?: string }`
  - `type AssistantReply = { transcript?: string; reply_text: string; fields: BookingFields; ready: boolean }`
  - `type PublicShop = { id: number; name: string; logo?: string | null; catalogs?: Array<{ id: number; title: string; price: number | string }>; slots?: unknown }`
  - `getPublicShop(id: number, date?: string): Promise<PublicShop>`
  - `bookAssistantText(shopId: number, text: string, state: BookingFields): Promise<AssistantReply>`
  - `bookAssistantVoice(shopId: number, audio: Blob, state: BookingFields): Promise<AssistantReply>`

- [ ] **Step 1: Write the failing test**

`admin/src/lib/publicBooking.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from '@/lib/api';
import { bookAssistantText, getPublicShop } from './publicBooking';

describe('publicBooking lib', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('normalizes an assistant reply', async () => {
    vi.spyOn(api, 'post').mockResolvedValue({ data: { reply_text: 'What day?', fields: { service: 'Cut' }, ready: false } });
    const res = await bookAssistantText(7, 'a cut', {});
    expect(res.reply_text).toBe('What day?');
    expect(res.fields.service).toBe('Cut');
    expect(res.ready).toBe(false);
  });

  it('defaults fields to an empty object when server omits them', async () => {
    vi.spyOn(api, 'post').mockResolvedValue({ data: { reply_text: 'Hi', ready: false } });
    const res = await bookAssistantText(7, 'hi', {});
    expect(res.fields).toEqual({});
  });

  it('fetches a public shop with an optional date param', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { id: 7, name: 'Acme', catalogs: [] } });
    const shop = await getPublicShop(7, '2026-07-12');
    expect(get).toHaveBeenCalledWith('/shops/7', { params: { date: '2026-07-12' } });
    expect(shop.name).toBe('Acme');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/lib/publicBooking.test.ts`
Expected: FAIL — `./publicBooking` module not found.

- [ ] **Step 3: Implement the lib**

`admin/src/lib/publicBooking.ts`:

```ts
import api from './api';

export type BookingFields = {
  service?: string;
  date?: string;
  start_time?: string;
  customer_name?: string;
  customer_phone?: string;
};

export type AssistantReply = {
  transcript?: string;
  reply_text: string;
  fields: BookingFields;
  ready: boolean;
};

export type PublicShop = {
  id: number;
  name: string;
  logo?: string | null;
  catalogs?: Array<{ id: number; title: string; price: number | string }>;
  slots?: unknown;
};

/** Public shop read (name, logo, services, working hours, slots). No auth needed. */
export async function getPublicShop(id: number, date?: string): Promise<PublicShop> {
  const { data } = await api.get(`/shops/${id}`, { params: date ? { date } : undefined });
  return (data?.data ?? data) as PublicShop;
}

function normalize(d: unknown): AssistantReply {
  const o = (d ?? {}) as Record<string, unknown>;
  const fields = o.fields && typeof o.fields === 'object' ? (o.fields as BookingFields) : {};
  return {
    transcript: typeof o.transcript === 'string' ? o.transcript : undefined,
    reply_text: typeof o.reply_text === 'string' ? o.reply_text : '',
    fields,
    ready: !!o.ready,
  };
}

export async function bookAssistantText(shopId: number, text: string, state: BookingFields): Promise<AssistantReply> {
  const { data } = await api.post(`/shops/${shopId}/book-assistant/text`, { text, state });
  return normalize(data);
}

export async function bookAssistantVoice(shopId: number, audio: Blob, state: BookingFields): Promise<AssistantReply> {
  const fd = new FormData();
  fd.append('audio', audio, 'voice.webm');
  fd.append('state', JSON.stringify(state));
  const { data } = await api.post(`/shops/${shopId}/book-assistant/voice`, fd);
  return normalize(data);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd admin && npx vitest run src/lib/publicBooking.test.ts`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add admin/src/lib/publicBooking.ts admin/src/lib/publicBooking.test.ts
git commit -m "feat(booking): public-booking frontend data layer"
```

---

### Task 3: Frontend — booking page (manual flow, end-to-end)

**Files:**
- Create: `admin/src/pages/PublicBooking.tsx`
- Create: `admin/src/styles/public-booking.css`
- Modify: `admin/src/App.tsx` (import + public route)
- Test: `admin/src/pages/PublicBooking.test.tsx`

**Interfaces:**
- Consumes: `getPublicShop`, `BookingFields` (Task 2); `createBooking` from `@/lib/bookings`; `Icons` from `@/components/Icons`.
- Produces: default-exported `PublicBooking` page mounted at `/book/:shopId`. Confirm calls `createBooking(shopId, { services:[{title,price}], charges, date, start_time, customer_name, customer_whatsapp })`.

- [ ] **Step 1: Write the failing test**

`admin/src/pages/PublicBooking.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as pub from '@/lib/publicBooking';
import * as bookingsLib from '@/lib/bookings';
import PublicBooking from './PublicBooking';

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/book/7']}>
      <Routes><Route path="/book/:shopId" element={<PublicBooking />} /></Routes>
    </MemoryRouter>,
  );
}

describe('PublicBooking', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('loads the shop and books a manual selection', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue({
      id: 7, name: 'FreshPress', catalogs: [{ id: 1, title: 'Classic Haircut', price: 30 }],
    });
    const create = vi.spyOn(bookingsLib, 'createBooking').mockResolvedValue({ id: 55 } as never);

    renderPage();
    const user = userEvent.setup();

    await screen.findByText('Classic Haircut');            // service chip rendered
    const confirm = screen.getByRole('button', { name: /confirm booking/i });
    expect(confirm).toBeDisabled();                        // nothing chosen yet

    await user.click(screen.getByText('Classic Haircut'));
    await user.type(screen.getByLabelText(/date/i), '2026-07-12');
    await user.type(screen.getByLabelText(/time/i), '15:00');
    await user.type(screen.getByLabelText(/your name/i), 'Sara');
    await user.type(screen.getByLabelText(/phone/i), '0501234567');

    expect(confirm).toBeEnabled();
    await user.click(confirm);

    await waitFor(() => expect(create).toHaveBeenCalledWith(7, expect.objectContaining({
      customer_name: 'Sara', customer_whatsapp: '0501234567', date: '2026-07-12',
      start_time: '15:00', charges: 30,
      services: [{ title: 'Classic Haircut', price: 30 }],
    })));
    await screen.findByText(/you're booked/i);             // confirmation screen
  });

  it('shows a friendly error when the shop link is invalid', async () => {
    vi.spyOn(pub, 'getPublicShop').mockRejectedValue(new Error('404'));
    renderPage();
    await screen.findByText(/booking link isn't available/i);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/pages/PublicBooking.test.tsx`
Expected: FAIL — `./PublicBooking` not found.

- [ ] **Step 3: Implement the page (manual flow)**

`admin/src/pages/PublicBooking.tsx`:

```tsx
import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { getPublicShop, type BookingFields, type PublicShop } from '@/lib/publicBooking';
import { createBooking } from '@/lib/bookings';
import '@/styles/public-booking.css';

const pad = (n: number) => String(n).padStart(2, '0');
const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; };

type Created = { service: string; date: string; start_time: string; customer_name: string };

export default function PublicBooking() {
  const { shopId } = useParams<{ shopId: string }>();
  const id = Number(shopId);

  const [shop, setShop] = useState<PublicShop | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [fields, setFields] = useState<BookingFields>({ date: todayIso() });
  const [booking, setBooking] = useState(false);
  const [error, setError] = useState('');
  const [created, setCreated] = useState<Created | null>(null);

  useEffect(() => {
    let alive = true;
    getPublicShop(id)
      .then((s) => { if (alive) setShop(s); })
      .catch(() => { if (alive) setLoadError(true); });
    return () => { alive = false; };
  }, [id]);

  const set = <K extends keyof BookingFields>(k: K, v: BookingFields[K]) => setFields((f) => ({ ...f, [k]: v }));

  const catalogs = shop?.catalogs ?? [];
  const priceFor = (title?: string): number => {
    const c = catalogs.find((x) => x.title.toLowerCase() === (title ?? '').toLowerCase());
    return c ? Number(c.price) || 0 : 0;
  };

  const ready = useMemo(() =>
    !!(fields.service && fields.date && fields.start_time && fields.customer_name && fields.customer_phone),
    [fields]);

  async function confirm() {
    if (!ready || !shop) return;
    setBooking(true); setError('');
    try {
      await createBooking(shop.id, {
        services: [{ title: fields.service!, price: priceFor(fields.service) }],
        charges: priceFor(fields.service),
        date: fields.date!,
        start_time: fields.start_time!,
        customer_name: fields.customer_name!,
        customer_whatsapp: fields.customer_phone!,
      });
      setCreated({ service: fields.service!, date: fields.date!, start_time: fields.start_time!, customer_name: fields.customer_name! });
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setError(msg && /closed/i.test(msg) ? "We're closed then — please pick another time." : (msg || 'Could not book right now — please try again.'));
    } finally {
      setBooking(false);
    }
  }

  if (loadError) {
    return <div className="pb-screen"><div className="pb-empty"><Icons.Store size={28} /><p>This booking link isn't available right now.</p></div></div>;
  }
  if (!shop) {
    return <div className="pb-screen"><div className="pb-empty"><p>Loading…</p></div></div>;
  }
  if (created) {
    return (
      <div className="pb-screen">
        <div className="pb-done">
          <div className="pb-done-tick"><Icons.Check size={30} /></div>
          <h2>You're booked!</h2>
          <p className="pb-done-sub">{created.service} · {created.date} at {created.start_time}</p>
          <p className="pb-done-sub">See you soon, {created.customer_name} — {shop.name}.</p>
          <button className="c-btn c-btn-block" onClick={() => { setCreated(null); setFields({ date: todayIso() }); }}>
            Book another
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="pb-screen">
      <header className="pb-head">
        {shop.logo ? <img className="pb-logo" src={shop.logo} alt="" /> : <span className="pb-logo pb-logo-empty">{shop.name.slice(0, 1)}</span>}
        <div><div className="pb-title">Book with {shop.name}</div><div className="pb-sub">Pick your service and time, or use the mic.</div></div>
      </header>

      <div className="pb-body">
        {/* Voice mic is added in Task 5; the form works on its own. */}
        <div className="pb-form">
          <label className="c-field-label">Service</label>
          <div className="pb-chips">
            {catalogs.map((c) => (
              <button key={c.id} type="button"
                className={`pb-chip ${fields.service === c.title ? 'is-on' : ''}`}
                onClick={() => set('service', c.title)}>
                {c.title}<span className="pb-chip-price">AED {c.price}</span>
              </button>
            ))}
          </div>

          <label className="c-field-label" htmlFor="pb-date">Date</label>
          <input id="pb-date" className="pb-input" type="date" value={fields.date ?? ''} onChange={(e) => set('date', e.target.value)} />

          <label className="c-field-label" htmlFor="pb-time">Time</label>
          <input id="pb-time" className="pb-input" type="time" value={fields.start_time ?? ''} onChange={(e) => set('start_time', e.target.value)} />

          <label className="c-field-label" htmlFor="pb-name">Your name</label>
          <input id="pb-name" className="pb-input" type="text" value={fields.customer_name ?? ''} onChange={(e) => set('customer_name', e.target.value)} />

          <label className="c-field-label" htmlFor="pb-phone">Phone (WhatsApp)</label>
          <input id="pb-phone" className="pb-input" type="tel" value={fields.customer_phone ?? ''} onChange={(e) => set('customer_phone', e.target.value)} />

          {error && <div className="c-error-box">{error}</div>}

          <button className="c-btn c-btn-block" disabled={!ready || booking} onClick={() => void confirm()}>
            {booking ? 'Booking…' : 'Confirm booking'}
          </button>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Add responsive styles**

`admin/src/styles/public-booking.css`:

```css
.pb-screen { min-height: 100dvh; max-width: 960px; margin: 0 auto; padding: 20px 16px 40px; color: var(--text-1); }
.pb-head { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
.pb-logo { width: 46px; height: 46px; border-radius: 12px; object-fit: cover; }
.pb-logo-empty { display: grid; place-items: center; background: var(--mint-900, #0f2a22); color: var(--mint-300); font-weight: 700; }
.pb-title { font-size: 18px; font-weight: 700; }
.pb-sub { font-size: 13px; color: var(--text-4); }

/* Side-by-side on desktop, stacked (mic on top) on mobile — the mic column is
   added in Task 5; the form spans full width until then. */
.pb-body { display: grid; gap: 20px; grid-template-columns: 1fr; }
@media (min-width: 1024px) { .pb-body.pb-has-mic { grid-template-columns: 360px 1fr; align-items: start; } }

.pb-form { display: flex; flex-direction: column; }
.pb-input { width: 100%; margin-bottom: 14px; padding: 12px 14px; border-radius: 12px;
  background: var(--bg-2); border: 1px solid var(--border-1); color: var(--text-1); font-size: 15px; }
.pb-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.pb-chip { display: inline-flex; align-items: center; gap: 8px; padding: 9px 13px; border-radius: 999px;
  background: var(--bg-2); border: 1px solid var(--border-1); color: var(--text-2); font-size: 14px; cursor: pointer; }
.pb-chip.is-on { background: var(--mint-400); border-color: var(--mint-400); color: #06231b; font-weight: 600; }
.pb-chip-price { font-size: 12px; opacity: 0.75; }

.pb-empty { min-height: 60dvh; display: grid; place-items: center; gap: 10px; text-align: center; color: var(--text-4); }
.pb-done { max-width: 420px; margin: 8dvh auto 0; text-align: center; }
.pb-done-tick { width: 64px; height: 64px; margin: 0 auto 14px; border-radius: 50%; display: grid; place-items: center;
  background: var(--mint-400); color: #06231b; }
.pb-done-sub { color: var(--text-4); margin: 4px 0; }
.pb-done .c-btn { margin-top: 18px; }
```

- [ ] **Step 5: Wire the public route**

In `admin/src/App.tsx`, add the import with the other page imports:

```tsx
import PublicBooking from '@/pages/PublicBooking';
```

and add the route inside the `{/* Public / full-screen */}` group (after the `/scan/:token` route):

```tsx
<Route path="/book/:shopId" element={<PublicBooking />} />
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd admin && npx vitest run src/pages/PublicBooking.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 7: Verify the build**

Run: `cd admin && npm run build`
Expected: build succeeds (tsc + vite).

- [ ] **Step 8: Commit**

```bash
git add admin/src/pages/PublicBooking.tsx admin/src/pages/PublicBooking.test.tsx admin/src/styles/public-booking.css admin/src/App.tsx
git commit -m "feat(booking): in-app public booking page (manual flow)"
```

---

### Task 4: Frontend — point the Booking QR at the in-app page

**Files:**
- Modify: `admin/src/pages/Profile.tsx` (`qrTarget`, and remove reliance on `CUSTOMER_WEB` for booking)
- Modify: `admin/src/pages/Profile.test.tsx` (add a QR-target assertion)

**Interfaces:**
- Consumes: nothing new. `qrTarget` becomes `${window.location.origin}/book/${shop.id}`.
- Produces: the Booking QR, "Copy link", "Share", and downloaded poster all use the in-app URL.

- [ ] **Step 1: Write the failing test**

Add to `admin/src/pages/Profile.test.tsx` inside the `describe('Profile', …)` block:

```tsx
  it('encodes the in-app booking URL in the Booking QR', async () => {
    setup();
    // The QR value is exposed via the copy-link button target; assert the copy call.
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    const user = userEvent.setup();
    const copyBtns = await screen.findAllByRole('button', { name: /copy link/i });
    await user.click(copyBtns[copyBtns.length - 1]); // the Booking QR copy button
    expect(writeText).toHaveBeenCalledWith(expect.stringContaining('/book/7'));
    expect(writeText).not.toHaveBeenCalledWith(expect.stringContaining('bookings.eloquentservice.com'));
  });
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/pages/Profile.test.tsx`
Expected: FAIL — `qrTarget` still points at `CUSTOMER_WEB` (`bookings.eloquentservice.com/shop/...`).

- [ ] **Step 3: Update `qrTarget`**

In `admin/src/pages/Profile.tsx`, change the `qrTarget` definition (currently line ~57):

```tsx
  // In-app self-service booking page (same admin app, public route). Replaces
  // the old external customer web app.
  const qrTarget = shop?.id ? `${window.location.origin}/book/${shop.id}` : '';
```

Leave `appUrl` and the App QR untouched. If `CUSTOMER_WEB` (line ~12) is now unused, remove the constant to satisfy the linter.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd admin && npx vitest run src/pages/Profile.test.tsx`
Expected: PASS (all Profile tests, including the new one).

- [ ] **Step 5: Commit**

```bash
git add admin/src/pages/Profile.tsx admin/src/pages/Profile.test.tsx
git commit -m "feat(booking): Booking QR opens the in-app booking page"
```

---

### Task 5: Frontend — hybrid voice (reactive mic + assistant fills the form)

**Files:**
- Modify: `admin/src/hooks/useRecorder.ts` (opt-in mic level meter)
- Modify: `admin/src/pages/PublicBooking.tsx` (mic column, assistant wiring, TTS)
- Modify: `admin/src/styles/public-booking.css` (mic states + animation)
- Test: `admin/src/pages/PublicBooking.voice.test.tsx`

**Interfaces:**
- Consumes: `useRecorder({ meter: true })` now returns `level: number` (0–1 RMS, 0 when not metering); `bookAssistantVoice`, `bookAssistantText` (Task 2); `speak` from `@/lib/simulation`.
- Produces: a mic button that fills `fields` from the assistant reply and plays the spoken reply; a typed fallback input calling `bookAssistantText`.

- [ ] **Step 1: Extend the recorder with an opt-in level meter**

Modify `admin/src/hooks/useRecorder.ts` to (a) accept an options arg, (b) expose `level`, computed from a Web Audio analyser only when `meter` is set. Full new file:

```ts
import { useRef, useState } from 'react';

function pickMime(): string | undefined {
  const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];
  for (const c of candidates) {
    if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported?.(c)) return c;
  }
  return undefined;
}

export function useRecorder(opts?: { meter?: boolean }) {
  const recorderRef = useRef<MediaRecorder | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const [recording, setRecording] = useState(false);
  const [level, setLevel] = useState(0);
  const ctxRef = useRef<AudioContext | null>(null);
  const rafRef = useRef<number | null>(null);
  const supported = typeof navigator !== 'undefined' && !!navigator.mediaDevices && typeof MediaRecorder !== 'undefined';

  function startMeter(stream: MediaStream) {
    try {
      const Ctx: typeof AudioContext = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
      const ctx = new Ctx();
      ctxRef.current = ctx;
      const analyser = ctx.createAnalyser();
      analyser.fftSize = 256;
      ctx.createMediaStreamSource(stream).connect(analyser);
      const buf = new Uint8Array(analyser.frequencyBinCount);
      const tick = () => {
        analyser.getByteTimeDomainData(buf);
        let sum = 0;
        for (let i = 0; i < buf.length; i++) { const v = (buf[i] - 128) / 128; sum += v * v; }
        setLevel(Math.min(1, Math.sqrt(sum / buf.length) * 2.4));
        rafRef.current = requestAnimationFrame(tick);
      };
      tick();
    } catch { /* metering is best-effort */ }
  }

  function stopMeter() {
    if (rafRef.current != null) cancelAnimationFrame(rafRef.current);
    rafRef.current = null;
    ctxRef.current?.close().catch(() => undefined);
    ctxRef.current = null;
    setLevel(0);
  }

  async function start(): Promise<void> {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    const mime = pickMime();
    const rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
    chunksRef.current = [];
    rec.ondataavailable = (e) => { if (e.data.size > 0) chunksRef.current.push(e.data); };
    recorderRef.current = rec;
    rec.start();
    setRecording(true);
    if (opts?.meter) startMeter(stream);
  }

  function stop(): Promise<Blob | null> {
    return new Promise((resolve) => {
      const rec = recorderRef.current;
      if (!rec) { resolve(null); return; }
      rec.onstop = () => {
        rec.stream.getTracks().forEach((t) => t.stop());
        stopMeter();
        const blob = new Blob(chunksRef.current, { type: rec.mimeType || 'audio/webm' });
        setRecording(false);
        resolve(blob.size > 0 ? blob : null);
      };
      rec.stop();
    });
  }

  return { recording, start, stop, supported, level };
}
```

- [ ] **Step 2: Write the failing voice test**

`admin/src/pages/PublicBooking.voice.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as pub from '@/lib/publicBooking';
import PublicBooking from './PublicBooking';

vi.mock('@/lib/simulation', () => ({ speak: vi.fn().mockResolvedValue('blob:fake') }));

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/book/7']}>
      <Routes><Route path="/book/:shopId" element={<PublicBooking />} /></Routes>
    </MemoryRouter>,
  );
}

describe('PublicBooking voice', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('typing to the assistant fills the form fields', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue({
      id: 7, name: 'FreshPress', catalogs: [{ id: 1, title: 'Classic Haircut', price: 30 }],
    });
    vi.spyOn(pub, 'bookAssistantText').mockResolvedValue({
      reply_text: 'Great, what day?', ready: false,
      fields: { service: 'Classic Haircut', customer_name: 'Sara' },
    });

    renderPage();
    const user = userEvent.setup();
    await screen.findByText('Classic Haircut');

    await user.type(screen.getByPlaceholderText(/tell me what you'd like/i), 'classic haircut, I am Sara');
    await user.click(screen.getByRole('button', { name: /send/i }));

    await waitFor(() => expect((screen.getByLabelText(/your name/i) as HTMLInputElement).value).toBe('Sara'));
    expect(screen.getByText('Great, what day?')).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/pages/PublicBooking.voice.test.tsx`
Expected: FAIL — no assistant input/mic in the page yet.

- [ ] **Step 4: Add the mic column + assistant wiring to the page**

In `admin/src/pages/PublicBooking.tsx`:

Add imports:

```tsx
import { useRecorder } from '@/hooks/useRecorder';
import { bookAssistantText, bookAssistantVoice } from '@/lib/publicBooking';
import { speak } from '@/lib/simulation';
```

Add state + handlers inside the component (after the existing `useState` hooks):

```tsx
  const { recording, start, stop, supported, level } = useRecorder({ meter: true });
  const [busy, setBusy] = useState(false);
  const [reply, setReply] = useState('');
  const [speaking, setSpeaking] = useState(false);
  const [draft, setDraft] = useState('');

  async function applyReply(r: { reply_text: string; fields: BookingFields }) {
    setFields((f) => ({ ...f, ...r.fields }));
    setReply(r.reply_text);
    if (r.reply_text) {
      try {
        const url = await speak(r.reply_text, 'nova');
        setSpeaking(true);
        const a = new Audio(url);
        a.onended = () => { setSpeaking(false); URL.revokeObjectURL(url); };
        a.onerror = () => setSpeaking(false);
        await a.play();
      } catch { setSpeaking(false); }
    }
  }

  async function sendText() {
    if (!draft.trim() || busy || !shop) return;
    setBusy(true); setError('');
    try { await applyReply(await bookAssistantText(shop.id, draft, fields)); setDraft(''); }
    catch { setError('Could not reach the assistant.'); }
    finally { setBusy(false); }
  }

  async function toggleMic() {
    if (!shop) return;
    if (recording) {
      setBusy(true);
      const blob = await stop();
      if (!blob) { setBusy(false); return; }
      try { await applyReply(await bookAssistantVoice(shop.id, blob, fields)); }
      catch { setError('Could not reach the assistant.'); }
      finally { setBusy(false); }
    } else {
      setError('');
      try { await start(); } catch { setError('Microphone permission needed.'); }
    }
  }

  const micState = recording ? 'listening' : busy ? 'thinking' : speaking ? 'speaking' : 'idle';
```

Give the body the mic modifier class and add the mic column as the first child of `.pb-body`:

```tsx
      <div className="pb-body pb-has-mic">
        <div className="pb-voice">
          <button
            className={`pb-mic pb-mic-${micState}`}
            style={{ ['--lvl' as string]: recording ? level.toFixed(3) : 0 }}
            aria-label={recording ? 'Stop' : 'Speak to book'}
            disabled={!supported || (busy && !recording)}
            onClick={() => void toggleMic()}
          >
            <span className="pb-mic-ring" aria-hidden />
            <span className="pb-mic-ring pb-mic-ring2" aria-hidden />
            <Icons.Mic size={34} />
          </button>
          <p className="pb-voice-cap">
            {micState === 'listening' ? 'Listening…' : micState === 'thinking' ? 'One sec…' : micState === 'speaking' ? 'Speaking…' : 'Tap and tell me what you need'}
          </p>
          {reply && <p className="pb-voice-reply">{reply}</p>}
          <div className="pb-voice-type">
            <input className="pb-input" placeholder="…or tell me what you'd like to book"
              value={draft} onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') void sendText(); }} disabled={busy} />
            <button className="c-btn" aria-label="Send" disabled={busy || !draft.trim()} onClick={() => void sendText()}>
              <Icons.Send size={16} />
            </button>
          </div>
        </div>

        <div className="pb-form">
          {/* …existing form markup unchanged… */}
        </div>
      </div>
```

(Keep the existing `.pb-form` block exactly as built in Task 3 — only wrap it alongside the new `.pb-voice` column and add the `pb-has-mic` class.)

- [ ] **Step 5: Add mic styles + animation**

Append to `admin/src/styles/public-booking.css`:

```css
.pb-voice { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 10px;
  padding: 18px 8px; }
.pb-mic { position: relative; width: 120px; height: 120px; border-radius: 50%; border: none; cursor: pointer;
  display: grid; place-items: center; color: #06231b; background: var(--mint-400);
  box-shadow: 0 10px 30px rgba(0,0,0,0.25); transition: transform 80ms linear;
  transform: scale(calc(1 + (var(--lvl, 0) * 0.35))); }
.pb-mic:disabled { opacity: 0.5; cursor: default; }
.pb-mic-ring { position: absolute; inset: -6px; border-radius: 50%; border: 2px solid var(--mint-400);
  opacity: calc(0.15 + (var(--lvl, 0) * 0.6)); transform: scale(calc(1 + (var(--lvl, 0) * 0.5))); pointer-events: none; }
.pb-mic-ring2 { inset: -14px; opacity: calc(0.08 + (var(--lvl, 0) * 0.4)); }
.pb-mic-idle { animation: pb-breathe 2.6s ease-in-out infinite; }
.pb-mic-thinking { animation: pb-spin-pulse 1s ease-in-out infinite; }
.pb-mic-speaking { animation: pb-breathe 1.1s ease-in-out infinite; }
@keyframes pb-breathe { 0%,100% { box-shadow: 0 10px 30px rgba(0,0,0,0.25); } 50% { box-shadow: 0 10px 42px rgba(0, 209, 160, 0.5); } }
@keyframes pb-spin-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.6; } }
@media (prefers-reduced-motion: reduce) { .pb-mic, .pb-mic-ring { animation: none; transition: none; } }
.pb-voice-cap { font-size: 13px; color: var(--text-4); }
.pb-voice-reply { font-size: 15px; color: var(--text-2); max-width: 320px; }
.pb-voice-type { display: flex; gap: 8px; width: 100%; max-width: 340px; margin-top: 6px; }
.pb-voice-type .pb-input { margin-bottom: 0; }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd admin && npx vitest run src/pages/PublicBooking.voice.test.tsx src/pages/PublicBooking.test.tsx`
Expected: PASS (manual flow still green + the new voice test).

- [ ] **Step 7: Verify the build**

Run: `cd admin && npm run build`
Expected: build succeeds.

- [ ] **Step 8: Commit**

```bash
git add admin/src/hooks/useRecorder.ts admin/src/pages/PublicBooking.tsx admin/src/pages/PublicBooking.voice.test.tsx admin/src/styles/public-booking.css
git commit -m "feat(booking): hybrid voice — reactive mic fills the booking form"
```

---

### Task 6: Full verification + deploy to staging

**Files:** none (verification only).

- [ ] **Step 1: Run the full frontend test + build**

Run: `cd admin && npx vitest run && npm run build`
Expected: all tests pass; build succeeds.

- [ ] **Step 2: Run the backend tests on the droplet**

Per the standing rules, run PHP tests on the droplet (php8.4), never local, never against the prod DB.
Run: `php artisan test --filter=PublicBookingAssistantTest`
Expected: PASS.

- [ ] **Step 3: Deploy to staging and verify end-to-end**

Deploy the admin frontend (`admin/deploy.ps1`) and backend to **staging** first (per the deploy-flow rule). On a phone:
- Open `/book/:shopId` (grab a real shop id) — form loads with the shop's services.
- Manual: pick a service, date, time, name, phone → Confirm → confirmation screen; verify the booking appears in that shop's owner Bookings.
- Voice: tap the mic, say "a classic haircut on Friday at 3pm, I'm Sara, 0501234567" → fields fill in, spoken reply plays, mic reacts to speech → Confirm.
- Scan the Profile **Booking QR** → it opens `/book/:shopId` in the app (not the old customer web app).

- [ ] **Step 4: Promote to prod**

Only once staging is verified great, promote code + frontend to prod. Back up the prod DB first per the standing rules. (No destructive migrations here — this feature adds no tables.)

---

## Self-Review

**Spec coverage:**
- Public no-auth page inside the app → Task 3 (route in the public group). ✓
- Voice + manual sharing one state → Task 3 (form) + Task 5 (voice merges into `fields`). ✓
- Freeform time, instantly-confirmed → Task 3 `confirm()` via `createBooking`; closed-day error surfaced. ✓
- Restricted customer voice assistant → Task 1 (`set_booking` only, no owner tools). ✓
- Reactive big animated mic → Task 5 (`useRecorder` meter + `--lvl` CSS). ✓
- Responsive every screen → Task 3 stacked / `≥1024px` side-by-side. ✓
- Booking QR points in-app → Task 4. ✓
- Don't reference old customer app → new page uses `c-*`/`va-*`/`Icons`; QR no longer targets `bookings.eloquentservice.com`. ✓
- Tests (backend endpoints, restricted tools implied by single tool def, Profile QR, page render) → Tasks 1–5. ✓

**Placeholder scan:** No TBD/TODO; the one "keep existing markup" note in Task 5 Step 4 references code fully written in Task 3 Step 3. ✓

**Type consistency:** `BookingFields` keys (`service, date, start_time, customer_name, customer_phone`) are identical across the lib (Task 2), page state (Task 3), assistant merge (Task 5), and backend `$allowed` (Task 1). `createBooking` payload matches `BookSlotPayload` (`customer_whatsapp`, `services:[{title,price}]`, `charges`). `useRecorder` returns `level` consumed as `--lvl`. ✓
