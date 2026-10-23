<?php

namespace App\Http\Resources;

use App\Support\Timestamp;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'sku' => $this->sku,
            'name' => $this->name,
            'unit' => $this->unit,
            'purchase_price' => $this->purchase_price !== null ? (string) $this->purchase_price : null,
            'selling_price' => (string) $this->selling_price,
            'ready_stock' => (string) $this->ready_stock,
            'pending_stock' => (string) $this->pending_stock,
            'minimum_stock' => (string) $this->minimum_stock,
            'image_path' => $this->image_path,
            'is_active' => $this->is_active,
            'created_at' => Timestamp::iso8601($this->created_at),
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
