<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $author = $this->author_name ?? $this->extractStringFromRawPayload([
            'author.name',
            'author.displayName',
            'author.fullName',
            'author',
            'user.name',
            'user.displayName',
            'authorName',
            'reviewerName',
            'userName',
            'displayName',
        ]);
        $rating = $this->rating !== null ? (float) $this->rating : $this->extractRatingFromRawPayload();

        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'author_name' => $author,
            'rating' => $rating,
            'body' => $this->body,
            'published_at' => $this->published_at,
        ];
    }

    /**
     * @param array<int, string> $paths
     */
    private function extractStringFromRawPayload(array $paths): ?string
    {
        if (!is_array($this->raw_payload)) {
            return null;
        }

        foreach ($paths as $path) {
            $value = data_get($this->raw_payload, $path);

            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            return trim($value);
        }

        return null;
    }

    private function extractRatingFromRawPayload(): ?float
    {
        if (!is_array($this->raw_payload)) {
            return null;
        }

        $paths = [
            'reviewRating.ratingValue',
            'reviewRating.value',
            'ratingValue',
            'rating',
            'score',
            'stars',
            'value',
            'mark',
        ];

        foreach ($paths as $path) {
            $value = data_get($this->raw_payload, $path);

            if (is_string($value)) {
                $value = str_replace(',', '.', $value);
            }

            if (!is_numeric($value)) {
                continue;
            }

            $rating = round((float) $value, 2);

            if ($rating > 5 && $rating <= 10) {
                $rating = round($rating / 2, 2);
            }

            if ($rating >= 0 && $rating <= 5) {
                return $rating;
            }
        }

        return null;
    }
}
