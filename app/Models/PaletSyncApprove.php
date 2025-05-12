<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaletSyncApprove extends Model
{
    use HasFactory;

    public $timestamps = true;
    protected $fillable = [
        'palet_id',
        'status',
    ];
    public function palet()
    {
        return $this->belongsTo(Palet::class);
    }

}
