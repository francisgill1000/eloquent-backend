<?php

namespace App\Traits;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait HasBase64Image
{
    public static function saveBase64Image(string $base64, string $folder): string
    {
        if (str_contains($base64, ',')) {
            [, $base64] = explode(',', $base64);
        }

        $image = base64_decode($base64);

        if ($image === false) {
            throw new \Exception('Invalid base64 image');
        }

        $path = $folder . '/' . Str::uuid() . '.png';

        Storage::disk('public')->put($path, $image);

        return Storage::disk('public')->url($path);
    }
}
