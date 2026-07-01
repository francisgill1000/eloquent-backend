<?php

namespace App\Support;

/**
 * Single source of truth for service categories. Shops pick one at
 * registration (locked afterwards); the WhatsApp bot uses it to speak as the
 * right kind of assistant for that shop's customers.
 */
class ServiceCategories
{
    /**
     * Sentinel category_id for shops that chose "Other" and typed their own
     * category name (stored in shops.custom_category). Not a real category, so
     * it is excluded from the LIST and from ids().
     */
    public const OTHER_ID = 0;

    public const LIST = [
        ['id' => 1, 'code' => '0001', 'name' => 'Barber',       'icon' => 'Scissors'],
        ['id' => 2, 'code' => '0002', 'name' => 'Plumbing',     'icon' => 'Pickaxe'],
        ['id' => 3, 'code' => '0003', 'name' => 'AC Repair',    'icon' => 'Wind'],
        ['id' => 4, 'code' => '0004', 'name' => 'Electrician',  'icon' => 'Zap'],
        ['id' => 5, 'code' => '0005', 'name' => 'Car Wash',     'icon' => 'Car'],
        ['id' => 6, 'code' => '0006', 'name' => 'Painting',     'icon' => 'Paintbrush'],
        ['id' => 7, 'code' => '0007', 'name' => 'Cleaning',     'icon' => 'Home'],
        ['id' => 8, 'code' => '0008', 'name' => 'Pest Control', 'icon' => 'ShieldCheck'],
        ['id' => 9, 'code' => '0009', 'name' => 'Salon',        'icon' => 'Sparkles'],
        ['id' => 10, 'code' => '0010', 'name' => 'Professional Services', 'icon' => 'Briefcase'],
        ['id' => 11, 'code' => '0011', 'name' => 'Laundry',      'icon' => 'Shirt'],
    ];

    public static function all(): array
    {
        return self::LIST;
    }

    public static function name(?int $id): ?string
    {
        foreach (self::LIST as $category) {
            if ($category['id'] === $id) {
                return $category['name'];
            }
        }
        return null;
    }

    public static function ids(): array
    {
        return array_column(self::LIST, 'id');
    }
}
