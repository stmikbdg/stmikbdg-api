<?php

namespace App\Models\Sidang;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StatusPengajuan extends Model
{
    
    /**
     * Model ini mengarah ke tabel PENGAJUAN db simak baru skema antrian
     */
    use HasFactory;

    protected $table = 'sikps.status_pengajuan';
    protected $connection;
    protected $guarded = ['id_status'];

    public $primaryKey = 'id_status';
    public $timestamps = false;

    public function __construct()
    {
        $this->connection = config('myconfig.database.first_connection');
    }
    public function pengajuanSidang()
    {
        return $this->belongsTo(Pengajuan::class, 'id_pengajuan_sidang', 'id');
    }
}
