<?php

namespace Tests\Unit;

use App\Support\Wa\Prompts;
use PHPUnit\Framework\TestCase;

class WaPromptsTest extends TestCase
{
    public function test_sales_prompt_contains_key_rules(): void
    {
        $this->assertStringContainsString('KEEP IT SHORT', Prompts::REZZY_SALES);
        $this->assertStringContainsString('create_business_account', Prompts::REZZY_SALES);
        $this->assertStringContainsString('50 AED', Prompts::REZZY_SALES);
        $this->assertStringContainsString('https://pay.ziina.com/eloquentservice/dRhj0YS4V?source=app', Prompts::REZZY_SALES);
    }

    public function test_provider_prompt_includes_shop_and_category(): void
    {
        $prompt = Prompts::provider('Glow Salon', 'Salon');

        $this->assertStringContainsString('Glow Salon, a salon business', $prompt);
        $this->assertStringContainsString('Never mention Rezzy', $prompt);
    }

    public function test_provider_prompt_without_category(): void
    {
        $prompt = Prompts::provider('Glow Salon', null);

        $this->assertStringContainsString('assistant for Glow Salon.', $prompt);
        $this->assertStringNotContainsString('business business', $prompt);
    }
}
