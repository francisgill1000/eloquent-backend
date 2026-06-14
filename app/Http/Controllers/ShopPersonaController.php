<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\Wa\PersonaResolver;
use App\Support\Wa\PromptGenerator;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The shop owner's control over their AI assistant. The saved prompt is the
 * single source of truth — it is sent to the model exactly as written. The
 * owner can write it manually or fill it with "Generate from profile", which
 * builds a complete prompt from their services, hours, staff and location.
 */
class ShopPersonaController extends Controller
{
    public function show(Request $request, PersonaResolver $personas)
    {
        $shop = $this->requireShop($request);

        return response()->json($this->payload($shop, $personas));
    }

    public function update(Request $request, PersonaResolver $personas)
    {
        $shop = $this->requireShop($request);

        $data = $request->validate([
            'persona' => ['nullable', 'string', 'max:20000'],
        ]);

        // Blank prompt = fall back to the profile-generated default.
        $persona = trim((string) ($data['persona'] ?? ''));
        $shop->update(['persona' => $persona !== '' ? $persona : null]);

        return response()->json([
            'message' => 'Assistant updated',
            ...$this->payload($shop->fresh(), $personas),
        ]);
    }

    /** Build a fresh prompt from the current profile (not saved until the owner saves it). */
    public function generate(Request $request)
    {
        $shop = $this->requireShop($request);

        return response()->json(['prompt' => PromptGenerator::generate($shop)]);
    }

    private function payload(Shop $shop, PersonaResolver $personas): array
    {
        $usingCustom = (bool) ($shop->persona && trim($shop->persona) !== '');

        return [
            'persona' => $shop->persona,
            'using_custom' => $usingCustom,
            // What actually runs: the saved prompt, or the generated default.
            'effective_prompt' => $personas->promptForShop($shop),
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
