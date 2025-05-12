<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaletResource extends JsonResource
{
    protected $status, $message;

    public function __construct($status, $message, $resource){
        $this->status = $status;
        $this->message = $message;
        $this->resource = $resource;
    }


    public function toArray(Request $request): array
    {
       return [
        "status" => $this->status,
        "message" => $this->message,
        "resource" => $this->resource
       ];
    }
}
