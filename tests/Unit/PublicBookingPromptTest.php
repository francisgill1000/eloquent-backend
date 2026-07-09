<?php
namespace Tests\Unit;

use App\Models\Shop;
use App\Support\Assistant\PublicBookingPrompt;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PublicBookingPromptTest extends TestCase
{
    private function shop(): Shop
    {
        $shop = new Shop();
        $shop->name = 'DEMO Marina Spa';
        // Avoid the DB: hand the model its catalogs relation directly.
        $shop->setRelation('catalogs', new Collection([
            ['title' => 'Hair Colour', 'price' => 200],
        ]));
        return $shop;
    }

    /**
     * The BK00037 lesson: the model must NOT be the one counting digits or
     * deciding validity — that's what looped a valid number six times.
     */
    public function test_forbids_the_model_from_counting_or_judging_the_number(): void
    {
        $prompt = PublicBookingPrompt::for($this->shop(), []);
        $this->assertMatchesRegularExpression('/do not count|don\'t count/i', $prompt);
    }

    public function test_tells_the_model_a_valid_number_on_file_is_confirmed(): void
    {
        $prompt = PublicBookingPrompt::for($this->shop(), ['customer_phone' => '0529284464']);
        // Authoritative, deterministic status line — the model must be TOLD the
        // number is valid rather than judging it. The exact digits appear so the
        // model can read them back with confidence.
        $this->assertStringContainsString('CONFIRMED VALID', $prompt);
        $this->assertStringContainsString('0529284464', $prompt);
        $this->assertMatchesRegularExpression('/do not ask|don\'t ask|accept/i', $prompt);
    }

    public function test_tells_the_model_to_re_ask_when_the_number_on_file_is_invalid(): void
    {
        $prompt = PublicBookingPrompt::for($this->shop(), ['customer_phone' => '052928']);
        // Must NOT claim confirmation for an invalid number, and must ask again.
        $this->assertStringNotContainsString('CONFIRMED VALID', $prompt);
        $this->assertMatchesRegularExpression('/not (a )?valid|isn.t valid|again/i', $prompt);
    }
}
