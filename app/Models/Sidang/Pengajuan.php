<?php

namespace App\Models\Sidang;

use App\Models\SIKPS\DataKpSkripsi;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pengajuan extends Model
{
    /**
     * Model ini mengarah ke tabel PENGAJUAN db simak baru skema antrian
     */
    use HasFactory;

    protected $table = 'sikps.pendaftaran_sidang';
    protected $connection;
    protected $guarded = ['id'];

    public $primaryKey = 'id';
    public $timestamps = false;

    public function __construct()
    {
        $this->connection = config('myconfig.database.first_connection');
    }
    
    public function statusPengajuan()
    {
        return $this->hasOne(StatusPengajuan::class, 'id_pengajuan_sidang', 'id');
    }

    public function data_kp_skripsi() {
        return $this->belongsTo(DataKpSkripsi::class, 'id_kp_skripsi', 'id');
    }
}
