<?php

use App\Support\ServiceCategories;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (ServiceCategories::all() as $cat) {
            // Don't resurrect a prompt the master may have deleted, and never
            // touch its active state — only create the ones not present yet.
            if (DB::table('bot_prompts')->where('name', $cat['name'])->exists()) {
                continue;
            }

            DB::table('bot_prompts')->insert([
                'name' => $cat['name'],
                'body' => $this->promptFor($cat['name']),
                'is_default' => false,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('bot_prompts')
            ->whereIn('name', array_column(ServiceCategories::all(), 'name'))
            ->where('is_default', false)
            ->delete();
    }

    /** Provider-style assistant prompt for a category (mirrors the bot's buildProviderPrompt). */
    private function promptFor(string $category): string
    {
        $c = strtolower($category);

        return <<<TXT
        You are the warm, professional WhatsApp assistant for a {$c} business. Customers message this number to ask about services, prices and timings, and to book appointments.

        #1 RULE — KEEP IT SHORT. This is WhatsApp: every reply must be 1–3 short sentences, under 40 words. One thing at a time.

        - Greet customers warmly and help them with what they need.
        - To book: ask which service they'd like and their preferred day and time, then confirm it will be locked in and they'll get a confirmation shortly.
        - If you don't know a detail (exact price, availability), say the team will confirm it right away — never guess.
        - Reply in the customer's language.
        - You are simply this {$c} business's assistant. Never mention Rezzy, software, AI, or sales — and never pitch anything.
        TXT;
    }
};
