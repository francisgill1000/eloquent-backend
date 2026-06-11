<?php

namespace App\Actions\Wa;

use App\Models\Shop;
use App\Support\ServiceCategories;

/**
 * In-chat onboarding: the Rezzy sales bot creates a provider account for a
 * lead right inside the WhatsApp conversation. Their WhatsApp number becomes
 * the account phone. Deterministic on purpose: the model never types IDs or
 * PINs itself. Ported from whatsapp-autoreply/lib/onboard.js.
 */
class OnboardBusiness
{
    private const APP_URL = 'https://bizrezzy.eloquentservice.com';

    public const TOOL = [
        'name' => 'create_business_account',
        'description' => 'Create the Rezzy account for this business owner. Use ONLY after the owner has explicitly confirmed their exact business name and category AND clearly agreed to sign up. The system sends them their login details automatically afterwards.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'business_name' => [
                    'type' => 'string',
                    'description' => "The owner's exact business name, as they confirmed it",
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => ['Barber', 'Plumbing', 'AC Repair', 'Electrician', 'Car Wash', 'Painting', 'Cleaning', 'Pest Control', 'Salon'],
                    'description' => 'The business category, confirmed with the owner',
                ],
            ],
            'required' => ['business_name', 'category'],
        ],
    ];

    /** Create (or recover) the account and return the exact message to send. */
    public function run(array $input, string $whatsappNumber): string
    {
        $name = trim((string) ($input['business_name'] ?? ''));
        $categoryId = $this->categoryId((string) ($input['category'] ?? ''));

        if ($name === '' || !$categoryId) {
            return "Almost there — I just need your exact business name and category to set you up. What's the business called?";
        }

        // One account per WhatsApp number: resend credentials instead of duplicating.
        if ($existing = $this->shopByPhone($whatsappNumber)) {
            return $this->credentialsMessage(
                "Good news — your Rezzy account for {$existing->name} is already created! 😊 Here are your login details again:",
                $existing->shop_code,
                $existing->pin
            );
        }

        if (Shop::where('name', $name)->exists()) {
            return "Hmm, a business named \"{$name}\" already exists on Rezzy. Is your business name maybe written slightly differently? Tell me the exact name and I'll set it up. 😊";
        }

        $shop = Shop::create([
            'name' => $name,
            'phone' => '+' . preg_replace('/\D+/', '', $whatsappNumber),
            'category_id' => $categoryId,
            'is_verified' => true,
            'category_confirmed_at' => now(),
        ]);

        return $this->credentialsMessage(
            "Great news — your Rezzy account for {$shop->name} is created! 😊 Here are your login details:",
            $shop->shop_code,
            $shop->pin
        );
    }

    private function categoryId(string $category): ?int
    {
        foreach (ServiceCategories::LIST as $c) {
            if ($c['name'] === $category) {
                return $c['id'];
            }
        }

        return null;
    }

    /** Find an existing account by phone (last-9-digit match) to avoid duplicates. */
    private function shopByPhone(string $number): ?Shop
    {
        $digits = preg_replace('/\D+/', '', $number);
        if (strlen($digits) < 7) {
            return null;
        }
        $tail = substr($digits, -9);

        return Shop::whereNotNull('phone')
            ->get(['id', 'name', 'phone', 'shop_code', 'pin'])
            ->first(function ($s) use ($tail) {
                $p = preg_replace('/\D+/', '', (string) $s->phone);

                return $p !== '' && substr($p, -9) === $tail;
            });
    }

    private function credentialsMessage(string $intro, string $shopCode, string $pin): string
    {
        return "{$intro}\n\n"
            . "Business ID: {$shopCode}\n"
            . "PIN: {$pin}\n\n"
            . 'Log in here: ' . self::APP_URL . "\n\n"
            . "Save these — you'll use them every time you log in.\n\n"
            . "The last step is connecting your WhatsApp booking line so it can start answering your customers — I'm on it, and I'll message you right here the moment it's live. 😊";
    }
}
