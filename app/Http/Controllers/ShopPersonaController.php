<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\Wa\PersonaResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The shop owner's window into their AI assistant: see the effective system
 * prompt (custom persona or category default) and edit it. The same persona
 * drives WhatsApp and in-app Live Chat replies.
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

        // Blank persona = back to the category default.
        $persona = trim((string) ($data['persona'] ?? ''));
        $shop->update(['persona' => $persona !== '' ? $persona : null]);

        return response()->json([
            'message' => 'Assistant updated',
            ...$this->payload($shop->fresh(), $personas),
        ]);
    }

    private function payload(Shop $shop, PersonaResolver $personas): array
    {
        $default = $personas->promptForShop(tap(clone $shop)->setAttribute('persona', null));

        return [
            'persona' => $shop->persona,
            'default_prompt' => $default,
            'effective_prompt' => $personas->promptForShop($shop),
            'using_custom' => (bool) ($shop->persona && trim($shop->persona) !== ''),
            // Appended automatically to every reply; shown read-only in the
            // editor so it never gets frozen into a saved custom persona.
            'business_facts' => \App\Support\Wa\ShopFacts::for($shop),
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
