<?php
namespace App\Services\Assistant\Support;

use App\Models\Shop;
use App\Models\ShopUser;

/** One assistant tool invocation, with the acting shop + user resolved. */
final class ToolCall
{
    public function __construct(
        public readonly Shop $shop,
        public readonly ?ShopUser $actingUser,
        public readonly string $tool,
        public readonly array $input,
        public readonly bool $confirmed,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }
}
