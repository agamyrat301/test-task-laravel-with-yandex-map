<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'yandex_review_id',
        'author', 'rating', 'text', 'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'date',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
