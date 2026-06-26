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
}
