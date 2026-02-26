<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RackResource extends JsonResource
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
            'name' => $this->name,
            'source' => $this->source, // staging / display
            'total_data' => (int) $this->total_data,
            'total_new_price_product' => (float) $this->total_new_price_product,
            'total_old_price_product' => (float) $this->total_old_price_product,
            'total_display_price_product' => (float) $this->total_display_price_product,
            'is_so' => (int) $this->is_so,
            'status_so' => $this->is_so == 1 ? 'Sudah SO' : 'Belum SO',
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
