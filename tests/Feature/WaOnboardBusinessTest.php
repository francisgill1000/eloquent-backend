<?php

namespace Tests\Feature;

use App\Actions\Wa\OnboardBusiness;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaOnboardBusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_shop_and_returns_credentials_message(): void
    {
        $message = (new OnboardBusiness())->run(
            ['business_name' => 'Glow Salon', 'category' => 'Salon'],
            '971555000111'
        );

        $shop = Shop::where('name', 'Glow Salon')->firstOrFail();
        $this->assertSame('+971555000111', $shop->phone);
        $this->assertSame(9, (int) $shop->category_id);
        $this->assertNotNull($shop->category_confirmed_at);
        $this->assertStringContainsString("Business ID: {$shop->shop_code}", $message);
        $this->assertStringContainsString("PIN: {$shop->pin}", $message);
        $this->assertStringContainsString('https://bizrezzy.eloquentservice.com', $message);
    }

    public function test_resends_credentials_for_existing_phone(): void
    {
        $existing = Shop::factory()->create(['name' => 'Old Salon', 'phone' => '+971555000111']);

        $message = (new OnboardBusiness())->run(
            ['business_name' => 'Different Name', 'category' => 'Salon'],
            '971555000111'
        );

        $this->assertSame(1, Shop::count()); // no duplicate created
        $this->assertStringContainsString('already created', $message);
        $this->assertStringContainsString("Business ID: {$existing->shop_code}", $message);
    }

    public function test_rejects_duplicate_business_name(): void
    {
        Shop::factory()->create(['name' => 'Glow Salon', 'phone' => '+971555999999']);

        $message = (new OnboardBusiness())->run(
            ['business_name' => 'Glow Salon', 'category' => 'Salon'],
            '971555000111'
        );

        $this->assertSame(1, Shop::count());
        $this->assertStringContainsString('already exists on Rezzy', $message);
    }

    public function test_asks_again_on_missing_or_bad_input(): void
    {
        $bad = [
            ['business_name' => '', 'category' => 'Salon'],
            ['business_name' => 'Glow', 'category' => 'Bakery'],
            [],
        ];
        foreach ($bad as $input) {
            $message = (new OnboardBusiness())->run($input, '971555000111');
            $this->assertStringContainsString('exact business name', $message);
        }
        $this->assertSame(0, Shop::count());
    }
}
