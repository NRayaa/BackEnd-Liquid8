<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BulkyDocument extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($bulkyDocument) {
            $currentMonth = now()->format('m');
            $currentYear = now()->format('Y');

            // Ambil nomor terakhir dari kode dokumen pada bulan dan tahun yang sama
            $lastDocument = self::whereMonth('created_at', $currentMonth)
                ->whereYear('created_at', $currentYear)
                ->orderByDesc('id')  // Urutkan berdasarkan ID yang baru-baru ini diinsert
                ->first();

            // Jika ada dokumen sebelumnya, ambil nomor urutan terakhir dan tambah 1
            $lastSequence = $lastDocument ? (int) substr($lastDocument->code_document_bulky, 0, 3) : 0;
            $sequence = str_pad($lastSequence + 1, 3, '0', STR_PAD_LEFT);
            $bulkyDocument->code_document_bulky = "{$sequence}/{$currentMonth}/{$currentYear}";
        });
    }

    public function bulkySales()
    {
        return $this->hasMany(BulkySale::class);
    }
}
