<?php

namespace App\Services\Ai;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\User;
use App\Support\ServiceCategories;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tools for the customer in-app assistant. Read tools run here, device-scoped
 * by X-Device-Id (only get_account needs a logged-in User). Action tools
 * (navigate/register/login) are declared but executed by the client; the agent
 * detects them and returns a directive instead of calling executeRead().
 */
class AssistantTools
{
    public const ACTION_TOOLS = ['navigate', 'register', 'login'];

    private Collection $collected;

    public function __construct(
        private string $deviceId,
        private ?User $user = null,
        private ?float $lat = null,
        private ?float $lon = null,
    ) {
        $this->collected = collect();
    }

    /** Full shop rows gathered by search_shops/get_shop/list_favourites this run. */
    public function collectedShops(): Collection
    {
        return $this->collected;
    }

    public static function defs(): array
    {
        $routes = '"/", "/explore", "/near-me", "/ai", "/favourites", "/bookings", "/account", "/login", "/register", or "/shop/{id}"';

        return [
            ['name' => 'list_categories', 'description' => 'List the service categories that currently have shops, with a count each.', 'input_schema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'search_shops', 'description' => 'Search active shops. Filter by free-text query and/or a category id (1-10). Set near=true to rank by distance from the user (only works if their location is known).', 'input_schema' => ['type' => 'object', 'properties' => [
                'query' => ['type' => 'string', 'description' => 'Free text, e.g. a shop or service name'],
                'category_id' => ['type' => 'integer', 'description' => 'A category id 1-10'],
                'near' => ['type' => 'boolean', 'description' => 'Rank by distance from the user'],
            ]]],
            ['name' => 'get_shop', 'description' => 'Get one shop with its services and working hours.', 'input_schema' => ['type' => 'object', 'properties' => [
                'shop_id' => ['type' => 'integer'],
            ], 'required' => ['shop_id']]],
            ['name' => 'list_favourites', 'description' => "List the shops this user has favourited.", 'input_schema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'list_bookings', 'description' => "List this user's bookings. scope is 'upcoming', 'history', or 'all'.", 'input_schema' => ['type' => 'object', 'properties' => [
                'scope' => ['type' => 'string', 'enum' => ['upcoming', 'history', 'all']],
            ]]],
            ['name' => 'get_account', 'description' => "Get the signed-in user's account details. Returns logged_in:false if they are a guest.", 'input_schema' => ['type' => 'object', 'properties' => (object) []]],

            ['name' => 'navigate', 'description' => "Take the user to an app screen. route is one of {$routes}.", 'input_schema' => ['type' => 'object', 'properties' => [
                'route' => ['type' => 'string', 'description' => "One of {$routes}"],
            ], 'required' => ['route']]],
            ['name' => 'register', 'description' => 'Start creating an account. Collect the name and phone in conversation first; the user types their password on a secure field (never ask for the password).', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'],
                'phone' => ['type' => 'string'],
            ]]],
            ['name' => 'login', 'description' => 'Start signing the user in. Collect the phone in conversation first; the user types their password on a secure field (never ask for the password).', 'input_schema' => ['type' => 'object', 'properties' => [
                'phone' => ['type' => 'string'],
            ]]],
        ];
    }

    /** Execute a read tool; always returns a JSON string for the model. */
    public function executeRead(string $name, array $input): string
    {
        $result = match ($name) {
            'list_categories' => $this->listCategories(),
            'search_shops' => $this->searchShops($input),
            'get_shop' => $this->getShop($input),
            'list_favourites' => $this->listFavourites(),
            'list_bookings' => $this->listBookings($input),
            'get_account' => $this->getAccount(),
            default => ['error' => "Unknown tool {$name}"],
        };

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    private function listCategories(): array
    {
        $counts = Shop::where('status', Shop::ACTIVE)
            ->where('is_master', false)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) as cnt')
            ->groupBy('category_id')
            ->pluck('cnt', 'category_id');

        $categories = collect(ServiceCategories::all())
            ->filter(fn ($c) => (int) ($counts[$c['id']] ?? 0) > 0)
            ->map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'count' => (int) ($counts[$c['id']] ?? 0)])
            ->values()
            ->all();

        return ['categories' => $categories];
    }

    private function searchShops(array $input): array
    {
        $query = Shop::query()
            ->where('status', Shop::ACTIVE)
            ->where('is_master', false);

        if (!empty($input['category_id']) && in_array((int) $input['category_id'], ServiceCategories::ids(), true)) {
            $query->where('category_id', (int) $input['category_id']);
        }
        if (!empty($input['query'])) {
            $q = trim((string) $input['query']);
            // Match the full phrase OR any individual word (>=3 chars), so a
            // voice mis-spelling ("Hina Salon" → "Heena Salon") still surfaces
            // the shop via a matching word like "Salon"; the model then picks it.
            $tokens = array_values(array_filter(
                preg_split('/\s+/', $q) ?: [],
                fn ($t) => mb_strlen($t) >= 3,
            ));
            $query->where(function ($w) use ($q, $tokens) {
                $w->where('name', 'LIKE', "%{$q}%")->orWhere('location', 'LIKE', "%{$q}%");
                foreach ($tokens as $t) {
                    $w->orWhere('name', 'LIKE', "%{$t}%");
                }
            });
        }

        $near = !empty($input['near']) && $this->lat !== null && $this->lon !== null;
        $distanceExpr = "(6371 * ACOS(LEAST(1, GREATEST(-1, COS(RADIANS(?)) * COS(RADIANS(lat)) * COS(RADIANS(lon) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(lat))))))";

        if ($near) {
            $query->whereNotNull('lat')->whereNotNull('lon')
                ->select('shops.*')->selectRaw($distanceExpr . ' as distance_km', [$this->lat, $this->lon, $this->lat]);
        }

        $query->withCount(['guest_favourites as is_favourite' => fn ($q) => $q->where('device_id', $this->deviceId)])
            ->with('today_working_hours');

        $near
            ? $query->orderByRaw($distanceExpr . ' asc', [$this->lat, $this->lon, $this->lat])
            : $query->orderByDesc('is_verified')->orderByDesc('id');

        $shops = $query->limit(15)->get();
        if ($near) {
            $shops->transform(function ($shop) {
                $shop->distance = number_format((float) ($shop->distance_km ?? 0), 1) . ' km';
                return $shop;
            });
        }

        $this->collected = $shops;

        return ['shops' => $shops->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'location' => $s->location,
            'rating' => $s->rating, 'is_favourite' => (bool) $s->is_favourite,
            'distance' => $s->distance ?? null,
        ])->all()];
    }

    private function getShop(array $input): array
    {
        $shop = Shop::where('status', Shop::ACTIVE)
            ->with(['today_working_hours', 'catalogs'])
            ->withCount(['guest_favourites as is_favourite' => fn ($q) => $q->where('device_id', $this->deviceId)])
            ->find((int) ($input['shop_id'] ?? 0));

        if (!$shop) {
            return ['error' => 'No such shop.'];
        }

        $this->collected = collect([$shop]);

        return ['shop' => [
            'id' => $shop->id, 'name' => $shop->name, 'location' => $shop->location,
            'rating' => $shop->rating, 'is_favourite' => (bool) $shop->is_favourite,
            'services' => $shop->catalogs->map(fn ($c) => ['title' => $c->title, 'price' => $c->price])->all(),
        ]];
    }

    private function listFavourites(): array
    {
        $shops = Shop::where('status', Shop::ACTIVE)
            ->whereHas('guest_favourites', fn ($q) => $q->where('device_id', $this->deviceId))
            ->withCount(['guest_favourites as is_favourite' => fn ($q) => $q->where('device_id', $this->deviceId)])
            ->with('today_working_hours')
            ->orderByDesc('id')
            ->get();

        $this->collected = $shops;

        return ['favourites' => $shops->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'location' => $s->location,
        ])->all()];
    }

    private function listBookings(array $input): array
    {
        $scope = $input['scope'] ?? 'all';
        $today = Carbon::now('Asia/Dubai')->startOfDay()->toDateString();

        $bookings = Booking::where('device_id', $this->deviceId)
            ->when($scope === 'upcoming', fn ($q) => $q->whereDate('date', '>=', $today))
            ->when($scope === 'history', fn ($q) => $q->whereDate('date', '<', $today))
            ->with('shop:id,name,location')
            ->orderByDesc('date')
            ->limit(20)
            ->get();

        return ['bookings' => $bookings->map(fn ($b) => [
            'reference' => $b->booking_reference,
            'date' => Carbon::parse($b->date)->toDateString(),
            'time' => $b->slot,
            'status' => $b->status,
            'shop' => $b->shop?->name,
            'services' => collect($b->services ?? [])->pluck('title')->filter()->values()->all(),
        ])->all()];
    }

    private function getAccount(): array
    {
        if (!$this->user) {
            return ['logged_in' => false];
        }

        return [
            'logged_in' => true,
            'name' => $this->user->name,
            'phone' => $this->user->phone,
        ];
    }
}
