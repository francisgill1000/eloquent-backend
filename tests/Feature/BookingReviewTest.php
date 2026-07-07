<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingReview;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Models\WaAccount;
use App\Services\Booking\BookingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.graph_version' => 'v25.0',
            'services.whatsapp.default_token' => 'system-token',
            'app.customer_url' => 'https://cust.example.com',
        ]);
    }

    private function shopWithWa(array $attrs = []): Shop
    {
        $shop = Shop::factory()->create(array_merge(['name' => 'Glow Salon'], $attrs));
        WaAccount::create([
            'shop_id' => $shop->id, 'phone_number' => '+971500000001',
            'phone_number_id' => 'pn_' . $shop->id, 'waba_id' => 'waba_' . $shop->id,
        ]);
        return $shop;
    }

    private function completedBooking(Shop $shop, array $attrs = []): Booking
    {
        $booking = Booking::create(array_merge([
            'shop_id' => $shop->id, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'booked',
            'customer_name' => 'Aisha', 'customer_whatsapp' => '971555000111',
            'charges' => 50, 'discount_amount' => 0, 'services' => [],
        ], $attrs));
        app(BookingStatusService::class)->apply($booking, 'completed');
        return $booking;
    }

    private function actingOwner(Shop $shop): string
    {
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();
        return $token->plainTextToken;
    }

    public function test_completing_booking_with_whatsapp_creates_one_pending_review(): void
    {
        $shop = $this->shopWithWa();
        $booking = $this->completedBooking($shop);

        $this->assertDatabaseCount('booking_reviews', 1);
        $review = BookingReview::first();
        $this->assertSame($booking->id, $review->booking_id);
        $this->assertSame($shop->id, $review->shop_id);
        $this->assertNull($review->rating);
        $this->assertNull($review->review_request_sent_at);

        // Idempotent: re-completing does not create a second review.
        app(BookingStatusService::class)->apply($booking->fresh(), 'completed');
        $this->assertDatabaseCount('booking_reviews', 1);
    }

    public function test_no_review_when_disabled_or_no_whatsapp(): void
    {
        $disabled = $this->shopWithWa(['booking_reviews_enabled' => false]);
        $this->completedBooking($disabled);

        $shop = $this->shopWithWa();
        $this->completedBooking($shop, ['customer_whatsapp' => null]);

        $this->assertDatabaseCount('booking_reviews', 0);
    }

    public function test_send_requests_command_sends_link_and_sets_flag(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
        $shop = $this->shopWithWa();
        $review = BookingReview::first() ?? null;
        $this->completedBooking($shop);
        $review = BookingReview::first();

        $this->artisan('reviews:send-requests')->assertSuccessful();

        $this->assertNotNull($review->fresh()->review_request_sent_at);
        Http::assertSent(function ($req) use ($review) {
            $body = $req->data()['text']['body'] ?? '';
            return str_contains($body, 'Glow Salon')
                && str_contains($body, 'https://cust.example.com/review/' . $review->token);
        });

        // Idempotent — second run sends nothing.
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.Y']]])]);
        $this->artisan('reviews:send-requests')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_public_get_and_post_funnels_high_rating_to_google_keeps_low_private(): void
    {
        $shop = $this->shopWithWa(['google_review_url' => 'https://g.page/glow/review']);
        $this->completedBooking($shop);
        $token = BookingReview::first()->token;

        $this->getJson("/api/reviews/{$token}")->assertOk()
            ->assertJsonPath('shop_name', 'Glow Salon')
            ->assertJsonPath('rated', false);

        // 5-star → returns Google URL for redirect.
        $this->postJson("/api/reviews/{$token}", ['rating' => 5, 'comment' => 'Great!'])
            ->assertOk()
            ->assertJsonPath('google_review_url', 'https://g.page/glow/review');

        $review = BookingReview::first()->fresh();
        $this->assertSame(5, $review->rating);
        $this->assertNotNull($review->rated_at);

        // A fresh low rating stays private (no Google URL) but is stored.
        $shop2 = $this->shopWithWa(['google_review_url' => 'https://g.page/x/review']);
        $this->completedBooking($shop2, ['customer_whatsapp' => '971555000222']);
        $token2 = BookingReview::where('shop_id', $shop2->id)->first()->token;
        $this->postJson("/api/reviews/{$token2}", ['rating' => 2, 'comment' => 'Slow'])
            ->assertOk()
            ->assertJsonPath('google_review_url', null);
        $this->assertSame(2, BookingReview::where('shop_id', $shop2->id)->first()->rating);
    }

    public function test_owner_list_returns_all_ratings_incl_private_scoped_to_shop_with_summary(): void
    {
        $shop = $this->shopWithWa();
        $other = $this->shopWithWa();
        // one 5-star, one 2-star for this shop; one for the other shop
        $this->completedBooking($shop);
        $this->completedBooking($shop, ['customer_whatsapp' => '971555000999']);
        $this->completedBooking($other);
        $reviews = BookingReview::where('shop_id', $shop->id)->get();
        $reviews[0]->update(['rating' => 5, 'rated_at' => now()]);
        $reviews[1]->update(['rating' => 2, 'rated_at' => now()]);
        BookingReview::where('shop_id', $other->id)->first()->update(['rating' => 1, 'rated_at' => now()]);

        $token = $this->actingOwner($shop);
        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/shop/reviews?shop_id=' . $shop->id)->assertOk();

        $this->assertCount(2, $res->json('data'));       // only this shop's, incl. the private 2-star
        $this->assertSame(2, $res->json('summary.count'));
        $this->assertEqualsWithDelta(3.5, $res->json('summary.average'), 0.01);
    }
}
