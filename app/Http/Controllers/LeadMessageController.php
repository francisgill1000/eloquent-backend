<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Shop;
use Illuminate\Http\Request;

/**
 * The shop owner's editable WhatsApp outreach templates for leads. Blank saves
 * fall back to the packaged defaults (Lead::DEFAULT_OPENING / DEFAULT_FOLLOWUP).
 * `{name}` in a template is replaced with the lead's business name at send time.
 */
class LeadMessageController extends Controller
{
    private function shop(Request $request): Shop
    {
        $shop = $request->user();
        abort_unless($shop instanceof Shop, 401, 'Shop authentication required.');
        return $shop;
    }

    public function show(Request $request)
    {
        return response()->json($this->payload($this->shop($request)));
    }

    public function update(Request $request)
    {
        $shop = $this->shop($request);

        $data = $request->validate([
            'opening' => ['nullable', 'string', 'max:2000'],
            'followup' => ['nullable', 'string', 'max:2000'],
        ]);

        $updates = [];
        if ($request->has('opening')) {
            $v = trim((string) ($data['opening'] ?? ''));
            $updates['lead_opening_template'] = $v !== '' ? $v : null;
        }
        if ($request->has('followup')) {
            $v = trim((string) ($data['followup'] ?? ''));
            $updates['lead_followup_template'] = $v !== '' ? $v : null;
        }
        if ($updates) {
            $shop->update($updates);
        }

        return response()->json($this->payload($shop->fresh()));
    }

    private function payload(Shop $shop): array
    {
        return [
            'opening' => $shop->lead_opening_template,
            'followup' => $shop->lead_followup_template,
            'default_opening' => Lead::DEFAULT_OPENING,
            'default_followup' => Lead::DEFAULT_FOLLOWUP,
        ];
    }
}
