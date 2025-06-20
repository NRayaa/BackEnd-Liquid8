<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BagProductResource extends JsonResource
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
            'user_id' => $this->user_id,
            'bulky_document_id' => $this->bulky_document_id,
            'total_product' => $this->total_product,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'bulky_sales' => $this->bulkySales->map(function ($sale) {
                return [
                    'id' => $sale->id,
                    'bulky_document_id' => $sale->bulky_document_id,
                    'barcode_bulky_sale' => $sale->barcode_bulky_sale,
                    'product_category_bulky_sale' => $sale->product_category_bulky_sale,
                    'name_product_bulky_sale' => $sale->name_product_bulky_sale,
                    'status_product_before' => $sale->status_product_before,
                    'old_price_bulky_sale' => $sale->old_price_bulky_sale,
                    'after_price_bulky_sale' => $sale->after_price_bulky_sale,
                    'created_at' => $sale->created_at,
                    'updated_at' => $sale->updated_at,
                    'bag_product_id' => $sale->bag_product_id,
                ];
            }),
        ];
    }
}