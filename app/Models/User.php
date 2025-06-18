<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected $appends = ['format_barcode_name'];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function generateApiKey()
    {
        $this->api_key = Str::random(60);
        $this->save();
        return $this->api_key;
    }

    public function format_barcode()
    {
        return $this->belongsTo(FormatBarcode::class);
    }

    public function user_scans()
    {
        return $this->hasMany(UserScan::class);
    }

    public function user_scan_webs()
    {
        return $this->hasMany(UserScanWeb::class);
    }

    public function scopeWithTotalScans(Builder $query)
    {
        $query->addSelect([
            'total_scans' => UserScanWeb::selectRaw('SUM(total_scans)')
                ->whereColumn('user_scan_webs.user_id', 'users.id')
        ]);
    }

    public function scopeTotalScanToday(Builder $query){
        $today = Carbon::now('Asia/Jakarta')->toDateString(); // Mendapatkan tanggal hari ini dalam format YYYY-MM-DD
    
        $query->addSelect([
            'total_scans_today' => UserScanWeb::selectRaw('SUM(total_scans)')
                ->whereColumn('user_scan_webs.user_id', 'users.id')
                ->whereDate('scan_date', $today) // Membatasi hanya untuk scan_date hari ini
        ]);
    }
    

    public function getFormatBarcodeNameAttribute()
    {
        return $this->format_barcode?->format;
    }

    public function bulkyDocuments(){
        return $this->hasMany(BulkyDocument::class, 'user_id');
    }
}
