<?php

namespace App\Http\Resources;

use App\Support\Timestamp;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => Timestamp::iso8601($this->created_at),
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
