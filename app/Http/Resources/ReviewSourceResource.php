<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewSourceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'source_url' => $this->source_url,
            'company_name' => $this->company_name,
            'company_rating' => $this->company_rating !== null ? (float) $this->company_rating : null,
            'company_reviews_count' => $this->company_reviews_count,
            'last_synced_at' => $this->last_synced_at,
        ];
    }
}
