<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'source_url',
        'company_name',
        'company_rating',
        'company_reviews_count',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'company_rating' => 'decimal:2',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
