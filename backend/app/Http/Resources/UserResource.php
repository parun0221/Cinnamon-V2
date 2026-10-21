<?php

namespace App\Http\Resources;

use App\Support\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'last_login_at' => Timestamp::iso8601($this->last_login_at),
            'created_at' => Timestamp::iso8601($this->created_at),
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
