<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Lead extends Model
{
    const STATUS_NEW = 'New';
    const STATUS_CONTACTED = 'Contacted';
    const STATUS_CONVERTED_TO_DEAL = 'Converted-to-Deal';
    const STATUS_INTERESTED = 'Interested';
    const STATUS_WON = 'Closed-Won';
    const STATUS_LOST = 'Closed-Lost';

    const SOURCE_FACEBOOK = 'Facebook';
    const SOURCE_INSTAGRAM = 'Instagram';
    const SOURCE_WEBSITE = 'Website';
    const SOURCE_WHATSAPP = 'WhatsApp';
    const SOURCE_REFERRAL = 'Referral';

    public static function statuses()
    {
        return [
            self::STATUS_NEW,
            self::STATUS_CONTACTED,
            self::STATUS_INTERESTED,
            self::STATUS_WON,
            self::STATUS_LOST,
        ];
    }

    public static function sources()
    {
        return [
            self::SOURCE_FACEBOOK,
            self::SOURCE_INSTAGRAM,
            self::SOURCE_WEBSITE,
            self::SOURCE_WHATSAPP,
            self::SOURCE_REFERRAL,
        ];
    }

    use HasFactory;

    protected $fillable = [
        'customer_id',
        'agent_id',
        'source',
        'status', // Add statuses: New, Contacted, Qualified, In Progress, Closed-Won, Closed-Lost.

        "notes",
        "attachments",
        "lat",
        "lon",
        "address",
    ];


    protected $casts = [
        'attachments' => 'array', // JSON array -> PHP array
    ];

    public function getAttachmentsAttribute($value)
    {
        // Decode JSON, fallback to empty array if null
        $files = json_decode($value, true);
        if (!is_array($files)) {
            $files = [];
        }

        if (!count($files)) {
            return $files;
        }

        // Prepend backend base URL
        $baseUrl = env("APP_URL"); // Ensure APP_URL is set in .env

        return array_map(fn($file) => $baseUrl . Storage::url($file), $files);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class);
    }
}
