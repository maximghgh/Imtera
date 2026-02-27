<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'review_source_id',
        'external_id',
        'author_name',
        'rating',
        'body',
        'published_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'published_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ReviewSource::class, 'review_source_id');
    }
}
