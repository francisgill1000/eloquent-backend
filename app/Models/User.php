<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'user_type',

        'phone',
        'whatsapp',
        'country',
        'city',

        'user_created_by_id',

    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Ensure the `name` attribute is always saved and returned in Title Case.
     * Uses multibyte-safe conversion.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function name(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn($value) => $value === null ? null : mb_convert_case($value, MB_CASE_TITLE, 'UTF-8'),
            set: fn($value) => $value === null ? null : mb_convert_case($value, MB_CASE_TITLE, 'UTF-8'),
        );
    }

    public function leadsAsCustomer()
    {
        return $this->hasMany(Lead::class, 'customer_id');
    }

    public function leadsAsAgent()
    {
        return $this->hasMany(Lead::class, 'agent_id');
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class);
    }
}
