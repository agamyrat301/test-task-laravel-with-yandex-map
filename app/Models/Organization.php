<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'yandex_url', 'yandex_org_id',
        'name', 'address', 'rating',
        'ratings_count', 'reviews_count', 'last_synced_at',
        'sync_status', 'sync_error',
    ];

    protected $casts = [
        'rating' => 'float',
        'last_synced_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
