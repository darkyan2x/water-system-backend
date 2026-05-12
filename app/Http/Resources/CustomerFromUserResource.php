<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerFromUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => (string) $this->name,
            // keep as string to preserve leading zeros
            'accountNumber' => (string) $this->account_number,
            'purok' => (string) $this->purok,
            'barangay' => (string) $this->barangay,
        ];
    }
}
