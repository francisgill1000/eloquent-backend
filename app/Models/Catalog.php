<?php

namespace App\Models;

use App\Traits\HasBase64Image;
use Illuminate\Database\Eloquent\Model;

class Catalog extends Model
{
    use HasBase64Image;

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    // Default catalog titles to create for a new shop
    // Default catalog data to create for a new shop
    public static function defaultServices(): array
    {
        return [
            [
                'title' => 'Classic Haircut',
                'image' => 'images/catalogs/classic-haircut.png',
                'price' => 10,
                'description' => 'Professional classic haircut tailored to your style.'
            ],
            [
                'title' => 'Fade Haircut',
                'image' => 'images/catalogs/fade-haircut.png',
                'price' => 10,
                'description' => 'Modern fade haircut for a sharp and clean look.'
            ],
            [
                'title' => 'Beard Trim',
                'image' => 'images/catalogs/beard-trim.png',
                'price' => 10,
                'description' => 'Precise beard trimming and shaping service.'
            ],
            [
                'title' => 'Hot Towel Shave',
                'image' => 'images/catalogs/hot-towel-shave.png',
                'price' => 10,
                'description' => 'Relaxing traditional hot towel shave experience.'
            ],
            [
                'title' => 'Cut + Beard',
                'image' => 'images/catalogs/cut-beard.png',
                'price' => 10,
                'description' => 'Complete haircut and beard grooming combo.'
            ],
            [
                'title' => 'Kids Haircut',
                'image' => 'images/catalogs/kid-haircut.png',
                'price' => 10,
                'description' => 'Friendly haircut service specially for kids.'
            ],
            [
                'title' => 'Facial',
                'image' => 'images/catalogs/facial.png',
                'price' => 10,
                'description' => 'Luxury facial treatment for refreshed skin.'
            ],
        ];
    }

    // Create default catalogs for a shop if none exist
    public static function createDefaultsForShop(Shop $shop): void
    {
        if ($shop->catalogs()->count() > 0) {
            return;
        }

        foreach (self::defaultServices() as $svc) {
            $shop->catalogs()->create([
                'title' => $svc['title'] ?? $svc['name'] ?? 'Service',
                'description' => $svc['description'] ?? null,
                'price' => $svc['price'] ?? 0,
                'image' => asset($svc['image']),
            ]);
        }
    }
}
