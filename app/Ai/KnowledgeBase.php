<?php

namespace App\Ai;

use App\Models\AiKbEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class KnowledgeBase
{
    private const CACHE_KEY = 'ai_kb_entries_cache_v1';
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Static seed entries — used as a fallback if the DB has none, and as the
     * source for `php artisan assistant:kb-seed`.
     * Each entry: id, patterns (regex array), answer, optional priority.
     */
    public static function seedEntries(): array
    {
        return [
            [
                'id'       => 'invoice_deflect',
                'priority' => 10,
                'patterns' => [
                    '/\binvoice(s)?\b/i',
                    '/\bbill(ing|s)?\b/i',
                    '/\bpay(ment)?\s+(link|reminder|due|overdue)\b/i',
                    '/\btax\s*invoice\b/i',
                    '/\breceipt\b/i',
                ],
                'answer'   => "Invoice and billing questions are handled by the shop owner in the Rezzy shop dashboard — I can't help with invoices here. For payment, cancellation, and booking questions, I'm happy to help.",
            ],
            [
                'id'       => 'greeting',
                'priority' => 20,
                'patterns' => ['/^\s*(hi|hey|hello|hola|salaam|assalamu? alaikum|good\s*(morning|afternoon|evening))\s*[!.?]*\s*$/i'],
                'answer'   => "Hi! I'm the Rezzy assistant. I can help you find nearby shops, book appointments, or answer questions about payments, cancellations, and working hours. What would you like to do?",
            ],
            [
                'id'       => 'thanks',
                'priority' => 20,
                'patterns' => ['/^\s*(thanks|thank\s*you|thx|ty|shukran|jazakallah)\s*[!.?]*\s*$/i'],
                'answer'   => "You're welcome! Let me know if there's anything else I can help with.",
            ],
            [
                'id'       => 'who_are_you',
                'priority' => 30,
                'patterns' => [
                    '/\bwho\s+are\s+you\b/i',
                    '/\bwhat\s+are\s+you\b/i',
                    '/\bwhat\s+can\s+you\s+do\b/i',
                ],
                'answer'   => "I'm the Rezzy AI Assistant. I can help you:\n- Find nearby shops (barber, salon, spa, AC technician, plumber, and more)\n- Understand how booking, cancellation, and payment work\n- Learn about shop registration and working hours\n\nTry: \"find barber near me\" or \"how do I cancel a booking?\"",
            ],
            [
                'id'       => 'what_is_rezzy',
                'priority' => 30,
                'patterns' => [
                    '/\bwhat\s+is\s+rezzy\b/i',
                    '/\babout\s+rezzy\b/i',
                    '/\btell\s+me\s+about\s+rezzy\b/i',
                ],
                'answer'   => "Rezzy is a UAE service-booking app (Sharjah-first) that connects customers with nearby shops — barbers, salons, spas, AC technicians, plumbers, and more. Customers find and book appointments; shops manage bookings, catalog, and working hours.",
            ],
            [
                'id'       => 'how_to_book',
                'priority' => 40,
                'patterns' => [
                    '/\bhow\s+(do|can|to)\b.*\b(book|make|create)\b.*\b(appointment|slot|booking)\b/i',
                    '/\bhow\s+to\s+book\b/i',
                    '/\bbook\s+an?\s+appointment\b.*\b(how|where)\b/i',
                ],
                'answer'   => "To book: open the shop's page, pick a service, choose an available time slot, and tap Book. You'll see the confirmation in your Bookings tab right after.",
            ],
            [
                'id'       => 'cancel_booking',
                'priority' => 40,
                'patterns' => [
                    '/\b(how\s+to|how\s+do\s+i|can\s+i)\b.*\bcancel\b.*\b(booking|appointment|slot)\b/i',
                    '/\bcancel\s+(my\s+)?(booking|appointment)\b/i',
                ],
                'answer'   => "You can cancel a booking from the Bookings tab — open the booking and tap Cancel. Cancellations are free if done before the shop's cancellation window (typically 2 hours before the slot). Past that, the shop may charge a fee.",
            ],
            [
                'id'       => 'reschedule_booking',
                'priority' => 40,
                'patterns' => ['/\b(reschedule|change|move|update)\b.*\b(booking|appointment|slot|time)\b/i'],
                'answer'   => "To reschedule: open the booking in the Bookings tab, tap Reschedule, then pick a new available slot. The shop is notified automatically.",
            ],
            [
                'id'       => 'where_are_my_bookings',
                'priority' => 40,
                'patterns' => [
                    '/\bwhere\b.*\b(my|see|find|view|check)\b.*\bbooking/i',
                    '/\bmy\s+bookings?\b/i',
                    '/\bbooking\s+history\b/i',
                ],
                'answer'   => "Your bookings live in the Bookings tab at the bottom of the app — upcoming and past bookings are both there.",
            ],
            [
                'id'       => 'payment_methods',
                'priority' => 40,
                'patterns' => [
                    '/\b(payment|pay)\s+(methods?|options?|types?)\b/i',
                    '/\bhow\s+do\s+i\s+pay\b/i',
                    '/\b(cash|card|credit\s*card|debit)\s+(accept|pay)/i',
                ],
                'answer'   => "You can pay in-app by card, or cash at the shop during your visit. The accepted methods may vary slightly per shop and are shown on the booking screen.",
            ],
            [
                'id'       => 'cancellation_policy',
                'priority' => 40,
                'patterns' => [
                    '/\bcancellation\s+(policy|window|rule|time)\b/i',
                    '/\bwhen\s+can\s+i\s+cancel\b/i',
                    '/\bhow\s+long\s+before\b.*\bcancel\b/i',
                ],
                'answer'   => "You can cancel free of charge up to 2 hours before your slot (some shops allow longer). After that, the shop may charge a cancellation fee.",
            ],
            [
                'id'       => 'register_shop',
                'priority' => 40,
                'patterns' => [
                    '/\b(register|sign\s*up|add|list|onboard)\b.*\b(shop|store|business|vendor)\b/i',
                    '/\bhow\s+to\s+(join|become)\b.*\b(shop|vendor|partner)\b/i',
                ],
                'answer'   => "To register your shop, tap \"Register your shop\" on the login screen and fill in your shop name, location, services, and working hours. Once approved, you'll receive a shop PIN to log in and manage bookings.",
            ],
            [
                'id'       => 'commission',
                'priority' => 40,
                'patterns' => [
                    '/\bcommission\b/i',
                    '/\b(rezzy|app)\s+fee\b/i',
                    '/\bhow\s+much\s+does\s+rezzy\s+(charge|take)\b/i',
                ],
                'answer'   => "Rezzy charges shops a small commission per completed booking. The exact rate depends on your plan and is shown in your shop dashboard.",
            ],
            [
                'id'       => 'working_hours_check',
                'priority' => 40,
                'patterns' => [
                    '/\bworking\s+hours\b/i',
                    '/\b(open|close|closing|opening)\s+(time|hours)\b/i',
                    '/\bis\s+.+\s+open\s+(now|today)\b/i',
                ],
                'answer'   => "Each shop's working hours are shown on its detail page, with an \"Open now\" or \"Closing soon\" label. Shop owners set their hours in the shop dashboard under Working Hours.",
            ],
            [
                'id'       => 'forgot_pin',
                'priority' => 40,
                'patterns' => [
                    '/\bforgot\b.*\b(pin|password)\b/i',
                    '/\breset\b.*\b(pin|password)\b/i',
                    '/\bcan\'?t\s+log\s*in\b/i',
                ],
                'answer'   => "Tap \"Forgot PIN?\" on the login screen and follow the reset flow. You'll get a verification code and can set a new PIN.",
            ],
            [
                'id'       => 'favourites',
                'priority' => 40,
                'patterns' => [
                    '/\b(favourites?|favorites?|saved\s+shops?)\b/i',
                    '/\bsave\s+(a\s+)?shop\b/i',
                ],
                'answer'   => "Tap the heart icon on any shop to save it to your Favourites. You can see all saved shops in the Favourites tab.",
            ],
            [
                'id'       => 'arabic_support',
                'priority' => 40,
                'patterns' => [
                    '/\b(arabic|عربي|العربية)\b/iu',
                    '/\blanguage(s)?\s+(support|available|offered)\b/i',
                ],
                'answer'   => "Yes — Rezzy supports English and Arabic. You can switch the app language from the Account tab.",
            ],
            [
                'id'       => 'contact_support',
                'priority' => 40,
                'patterns' => [
                    '/\b(contact|reach|talk\s+to|speak\s+to)\b.*\b(support|team|customer\s*service|help\s*desk)\b/i',
                    '/\b(support|help)\s+(email|phone|number|contact)\b/i',
                ],
                'answer'   => "You can reach Rezzy support from the Account tab → Help & Support, or email the team at support@eloquentservice.com.",
            ],
        ];
    }

    /**
     * Active entries, preferring DB rows; falls back to seedEntries() if DB unavailable or empty.
     * Cached for 5 minutes.
     *
     * @return array<int,array{id:string,patterns:array<int,string>,answer:string,priority:int,_db_id:?int}>
     */
    public static function entries(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            try {
                if (! Schema::hasTable('ai_kb_entries')) {
                    return self::fromSeed();
                }

                $rows = AiKbEntry::query()
                    ->where('enabled', true)
                    ->orderBy('priority')
                    ->orderBy('id')
                    ->get();

                if ($rows->isEmpty()) {
                    return self::fromSeed();
                }

                return $rows->map(fn ($r) => [
                    'id'       => $r->kb_id,
                    'patterns' => is_array($r->patterns) ? $r->patterns : [],
                    'answer'   => $r->answer,
                    'priority' => (int) $r->priority,
                    '_db_id'   => $r->id,
                ])->all();
            } catch (Throwable $e) {
                return self::fromSeed();
            }
        });
    }

    private static function fromSeed(): array
    {
        return array_map(fn ($e) => $e + ['_db_id' => null, 'priority' => $e['priority'] ?? 100], self::seedEntries());
    }

    /**
     * Find the first matching entry for a user message, or null if none match.
     * On hit, increments hit_count on the DB row (if present).
     *
     * @return array{id:string, answer:string}|null
     */
    public static function match(string $message): ?array
    {
        $msg = trim($message);
        if ($msg === '') {
            return null;
        }

        foreach (self::entries() as $entry) {
            foreach ($entry['patterns'] as $pattern) {
                if (@preg_match($pattern, $msg)) {
                    if (! empty($entry['_db_id'])) {
                        try {
                            DB::table('ai_kb_entries')->where('id', $entry['_db_id'])->increment('hit_count');
                        } catch (Throwable $e) {
                            // silent — logging for KB hits shouldn't break the request
                        }
                    }

                    return ['id' => $entry['id'], 'answer' => $entry['answer']];
                }
            }
        }

        return null;
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
