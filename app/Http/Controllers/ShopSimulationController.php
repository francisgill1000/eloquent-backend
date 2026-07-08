<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Per-shop demo-simulation script. This drives a client-side, dry-run replay
 * of a voice booking for recording marketing videos — it NEVER creates a real
 * booking. Only the script config is stored here. Null = use a default built
 * from the shop's own catalogs/staff (no hardcoded tenant identity).
 */
class ShopSimulationController extends Controller
{
    private const VOICES = ['nova', 'shimmer', 'coral', 'sage', 'alloy'];

    public function show(Request $request)
    {
        $shop = $this->requireShop($request);
        $script = $shop->simulation_script ?: $this->defaultScript($shop);

        return response()->json(['script' => $script]);
    }

    public function update(Request $request)
    {
        $shop = $this->requireShop($request);

        $data = $request->validate([
            'script' => ['nullable', 'array'],
            'script.turns' => ['sometimes', 'array'],
            'script.turns.*.who' => ['required_with:script.turns', 'in:owner,assistant'],
            'script.turns.*.text' => ['required_with:script.turns', 'string', 'max:800'],
            'script.voices.owner' => ['sometimes', 'in:' . implode(',', self::VOICES)],
            'script.voices.assistant' => ['sometimes', 'in:' . implode(',', self::VOICES)],
            'script.thinking_ms' => ['sometimes', 'integer', 'min:0', 'max:6000'],
            'script.booking' => ['sometimes', 'array'],
        ]);

        $shop->update(['simulation_script' => $data['script'] ?? null]);

        $fresh = $shop->fresh();
        return response()->json([
            'message' => 'Simulation saved',
            'script' => $fresh->simulation_script ?: $this->defaultScript($fresh),
        ]);
    }

    /** Build a believable default from the shop's real first catalog + staff. */
    private function defaultScript(Shop $shop): array
    {
        // Shop offerings are `catalogs` (Catalog: title + price). Staff is `staff` (Staff: name).
        $service = $shop->catalogs()->orderBy('id')->first();
        $staff = $shop->staff()->orderBy('id')->first();

        $serviceName = $service->title ?? 'Hair Cut';
        $price = (string) ($service->price ?? '150.00');
        $staffName = $staff->name ?? 'our stylist';
        $tomorrow = Carbon::tomorrow();

        return [
            'turns' => [
                ['who' => 'owner', 'text' => "Book Sarah in for a {$serviceName} tomorrow at 9:30."],
                ['who' => 'assistant', 'text' => "Done — Sarah's booked for a {$serviceName} tomorrow at 9:30am with {$staffName}. Want me to text her a confirmation?"],
                ['who' => 'owner', 'text' => 'Yes please, send it.'],
                ['who' => 'assistant', 'text' => "Sent. Sarah will get a WhatsApp confirmation and a reminder the day before. Anything else?"],
            ],
            'booking' => [
                'customer_name' => 'Sarah',
                'customer_phone' => '055 010 2030',
                'service' => $serviceName,
                'price' => $price,
                'date' => $tomorrow->toDateString(),
                'start_time' => '09:30',
                'end_time' => '10:00',
                'staff_name' => $staffName,
            ],
            'voices' => ['owner' => 'shimmer', 'assistant' => 'nova'],
            'thinking_ms' => 1400,
        ];
    }

    private function requireShop(Request $request): Shop
    {
        $user = $request->user();
        if (!$user || !($user instanceof Shop)) {
            throw new HttpException(403, 'Shop authentication required');
        }
        return $user;
    }
}
