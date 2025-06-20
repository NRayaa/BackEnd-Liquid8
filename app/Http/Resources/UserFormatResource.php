<?php
 
namespace App\Http\Resources;

use Illuminate\Http\Request;

use Illuminate\Http\Resources\Json\JsonResource;

class UserFormatResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'role_id' => $this->role_id,
            'format_barcode_id' => $this->format_barcode_id,
            'user_scans' => $this->user_scans->map(function ($scan) {
                return [
                    'id' => $scan->id,
                    'format_barcode_id' => $scan->format_barcode_id,
                    'format' => $scan->formatBarcode->format ?? null, 
                    'user_id' => $scan->user_id,
                    'total_scans' => $scan->total_scans,
                    'scan_date' => $scan->scan_date,
                ];
            }),
        ];
    }
}
