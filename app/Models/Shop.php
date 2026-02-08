<?php

namespace App\Models;

use App\Traits\HasBase64Image;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Shop extends Model
{
    use HasBase64Image;

    const INACTIVE = "inactive";
    const ACTIVE = "active";

    protected $guarded = [];

    protected $appends = ["registered_at", "year_of_experience", "total_bookings", "is_favourite"];

    protected static function booted()
    {
        static::creating(function ($shop) {
            $shop->status = self::ACTIVE;
            $shop->shop_code = mt_rand(100000, 999999);
        });
    }

    protected static function getFakeShops(): array
    {
        return  [
            [
                'id' => 1,
                'name' => 'Precision Plumbing Co.',
                'working_status' => 'Open Now',
                'statusColor' => 'text-emerald-400',
                'logo' => 'https://images.unsplash.com/photo-1581578731548-c64695cc6952',
                'hero_image' => 'https://images.unsplash.com/photo-1581578731548-c64695cc6952',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => true,
                'category_id' => 1,
            ],
            [
                'id' => 2,
                'name' => 'FlowMaster Repairs',
                'working_status' => 'Closing Soon',
                'statusColor' => 'text-amber-400',
                'logo' => 'https://images.unsplash.com/photo-1581092580497-e0d23cbdf1dc',
                'hero_image' => 'https://images.unsplash.com/photo-1581092580497-e0d23cbdf1dc',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => false,
                'category_id' => 2,
            ],
            [
                'id' => 3,
                'name' => 'Elite Pipe Solutions',
                'working_status' => 'Open Now',
                'statusColor' => 'text-emerald-400',
                'logo' => 'https://images.unsplash.com/photo-1621905252507-b35492cc74b4',
                'hero_image' => 'https://images.unsplash.com/photo-1621905252507-b35492cc74b4',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => true,
                'category_id' => 1,
            ],
            [
                'id' => 4,
                'name' => 'Reliable Rooters',
                'working_status' => 'Open Now',
                'statusColor' => 'text-emerald-400',
                'logo' => 'https://images.unsplash.com/photo-1600566752355-35792bedcfea',
                'hero_image' => 'https://images.unsplash.com/photo-1600566752355-35792bedcfea',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => false,
                'category_id' => 2,
            ],
            [
                'id' => 5,
                'name' => 'RapidFix Plumbing',
                'working_status' => 'Available Today',
                'statusColor' => 'text-sky-400',
                'logo' => 'https://images.unsplash.com/photo-1584622650111-993a426fbf0a',
                'hero_image' => 'https://images.unsplash.com/photo-1584622650111-993a426fbf0a',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => true,
                'category_id' => 1,
            ],
            [
                'id' => 6,
                'name' => 'AquaCare Services',
                'working_status' => 'Open Now',
                'statusColor' => 'text-emerald-400',
                'logo' => 'https://images.unsplash.com/photo-1590856029620-9c9a60c9b4a6',
                'hero_image' => 'https://images.unsplash.com/photo-1590856029620-9c9a60c9b4a6',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => false,
                'category_id' => 2,
            ],
            [
                'id' => 7,
                'name' => 'PrimeFlow Experts',
                'working_status' => 'Limited Slots',
                'statusColor' => 'text-orange-400',
                'logo' => 'https://images.unsplash.com/photo-1617104551722-3b2d0c66fc3f',
                'hero_image' => 'https://images.unsplash.com/photo-1617104551722-3b2d0c66fc3f',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => true,
                'category_id' => 1,
            ],
            [
                'id' => 8,
                'name' => 'CityWide Plumbing',
                'working_status' => 'Open Now',
                'statusColor' => 'text-emerald-400',
                'logo' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c',
                'hero_image' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => false,
                'category_id' => 2,
            ],
            [
                'id' => 9,
                'name' => 'PipePro Mechanics',
                'working_status' => 'Closing Soon',
                'statusColor' => 'text-amber-400',
                'logo' => 'https://images.unsplash.com/photo-1600573472591-ee6c34c8a5b5',
                'hero_image' => 'https://images.unsplash.com/photo-1600573472591-ee6c34c8a5b5',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => false,
                'category_id' => 2,
            ],
            [
                'id' => 10,
                'name' => 'Neighborhood Plumbers',
                'working_status' => 'Available Today',
                'statusColor' => 'text-sky-400',
                'logo' => 'https://images.unsplash.com/photo-1600566753086-00f18fb6b3ea',
                'hero_image' => 'https://images.unsplash.com/photo-1600566753086-00f18fb6b3ea',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => true,
                'category_id' => 1,
            ],
        ];
    }

    public static function findFake(?int $id = null): array
    {
        $shops = self::getFakeShops();

        if ($id === null) {
            return $shops; // return all shops
        }

        $filtered = array_filter($shops, fn($shop) => $shop['id'] === $id);

        return array_values($filtered); // return as indexed array
    }

    public function getRegisteredAtAttribute()
    {
        return date('D j, Y', strtotime($this->created_at));
    }

    public function getYearOfExperienceAttribute()
    {
        $created = new \DateTime($this->created_at);
        $now = new \DateTime();
        $interval = $created->diff($now);
        return $interval->y == 0 ? 1 : $interval->y;
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function getTotalBookingsAttribute()
    {
        return $this->bookings()->count();
    }

    public function working_hours()
    {
        return $this->hasMany(ShopWorkingHour::class);
    }

    public function today_working_hours()
    {
        return $this->hasOne(ShopWorkingHour::class)->where('day_of_week', date('w'));
    }

    public function getIsOpenAttribute()
    {
        $workingHour = $this->today_working_hours;

        if (!$workingHour) {
            return false;
        }

        $now = Carbon::now()->format('H:i:s');

        return $now >= $workingHour->start_time
            && $now <= $workingHour->end_time;
    }

    public function guest_favourites()
    {
        return $this->hasMany(GuestFavourite::class);
    }

    public function getIsFavouriteAttribute()
    {
        $deviceId = request()->header('X-Device-Id');

        if (!$deviceId) {
            return false;
        }

        return $this->guest_favourites()
            ->where('device_id', $deviceId)
            ->exists();
    }

    public function getWorkingHourOrFail(string $date)
    {
        $dayOfWeek = Carbon::parse($date)->dayOfWeek;

        $workingHour = $this->working_hours()
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if (!$workingHour) {
            throw new HttpException(
                400,
                'Shop is closed on this day'
            );
        }

        return $workingHour;
    }

    public function getEndSlot($startTime, $slot_duration = 30)
    {
        return Carbon::parse($startTime)
            ->addMinutes($slot_duration)
            ->format('H:i');
    }

    public static function getSlots($date, $start, $end, $slot_duration, $shop_id)
    {
        $slots = [];

        // Get already booked slots
        $bookedSlots = Booking::where('shop_id', $shop_id)
            ->where('date', $date)
            ->where('status', 'booked')
            ->pluck('start_time')
            ->map(fn($time) => Carbon::parse($time)->format('H:i'))
            ->toArray() ?? [];

        // Generate available slots
        $start = Carbon::createFromTimeString($start);
        $end   = Carbon::createFromTimeString($end);

        while ($start->lt($end)) {
            $time = $start->format('H:i');
            if (!in_array($time, $bookedSlots)) {
                $slots[] = $time;
            }
            $start->addMinutes($slot_duration);
        }

        return $slots;
    }

    public function catalogs()
    {
        return $this->hasMany(Catalog::class);
    }
}
