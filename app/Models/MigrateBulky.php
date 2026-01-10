<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MigrateBulky extends Model
{
    use HasFactory;

    protected $table = 'migrate_bulkies';

    protected $guarded = ['id'];

    public function migrateBulkyProducts()
    {
        return $this->hasMany(MigrateBulkyProduct::class, 'migrate_bulky_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['q'] ?? false, function ($query, $search) {
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('code_document', 'LIKE', '%' . $search . '%')

                    ->orWhereHas('user', function ($q) use ($search) {
                        $q->where('name', 'LIKE', '%' . $search . '%');
                    })

                    ->orWhereHas('migrateBulkyProducts', function ($q) use ($search) {
                        $q->where('new_barcode_product', 'LIKE', '%' . $search . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $search . '%')
                            ->orWhere('new_name_product', 'LIKE', '%' . $search . '%');
                    });
            });
        });
    }
}
