<?php
namespace Tests\Feature;

use App\Models\Shop;
use App\Support\Assistant\AssistantPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_includes_shop_name_currency_and_confirm_rule(): void
    {
        $shop = Shop::create(['name' => 'FreshPress Laundry', 'shop_code' => '1001', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $prompt = AssistantPrompt::for($shop);

        $this->assertStringContainsString('FreshPress Laundry', $prompt);
        $this->assertStringContainsString('dirhams', $prompt);
        $this->assertStringContainsString(now()->toDateString(), $prompt);
        $this->assertStringContainsString('confirm', strtolower($prompt));
    }

    public function test_leads_only_shop_prompt_has_hunt_and_no_booking_section(): void
    {
        $shop = Shop::create(['name' => 'Hunt Co', 'shop_code' => '7200', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['leads']]);
        $prompt = AssistantPrompt::for($shop);
        $this->assertStringContainsString('BUSINESS HUNT', $prompt);
        $this->assertStringContainsString('search_businesses', $prompt);
        $this->assertStringNotContainsString('BOOKINGS & SERVICES', $prompt);
    }

    public function test_bookings_only_shop_prompt_has_bookings_and_no_hunt_section(): void
    {
        $shop = Shop::create(['name' => 'B', 'shop_code' => '7201', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['bookings']]);
        $prompt = AssistantPrompt::for($shop);
        $this->assertStringContainsString('BOOKINGS & SERVICES', $prompt);
        $this->assertStringNotContainsString('BUSINESS HUNT', $prompt);
    }

    public function test_multi_module_shop_prompt_has_both_sections(): void
    {
        $shop = Shop::create(['name' => 'Both', 'shop_code' => '7202', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['bookings', 'leads']]);
        $prompt = AssistantPrompt::for($shop);
        $this->assertStringContainsString('BOOKINGS & SERVICES', $prompt);
        $this->assertStringContainsString('BUSINESS HUNT', $prompt);
    }

    /**
     * Guards against the "assistant said 10 credits while the real balance was
     * 548" hallucination: the prompt must forbid stating a number without
     * calling the tool that produces it, naming hunt_credits explicitly.
     */
    public function test_prompt_forbids_stating_numbers_without_calling_a_tool(): void
    {
        $shop = Shop::create(['name' => 'Hunt Co', 'shop_code' => '7203', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['leads']]);
        $prompt = AssistantPrompt::for($shop);
        $this->assertStringContainsString('NEVER state any number', $prompt);
        $this->assertStringContainsString('hunt_credits for credits', $prompt);
    }
}
