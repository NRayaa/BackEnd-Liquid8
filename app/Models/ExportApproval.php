<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExportApproval extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'filter_data' => 'array',
        'approved_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}