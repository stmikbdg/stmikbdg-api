<?php

namespace App\Models\SIKPS;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SIKPS\DataKpSkripsi;

class MasterBimbingan extends Model
{
    use HasFactory;

    protected $table = 'sikps.master_bimbingan';
    protected $connection;

    public $primaryKey = 'id';
    protected $fillable = [
        'bab',
        'jenis_dokumen',
        'dokumen',
        'jenis_bimbingan',
        'status',
        'is_arsip',
        'data_kp_skripsi_id',
        'progress',
        'catatan'
    ];
    public $increment = true;
    public $timestamps = true;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function data_kp_skripsi() {
        return $this->belongsTo(DataKpSkripsi::class, 'data_kp_skripsi_id', 'id');
    }

    public function master_absensi() {
        return $this->hasMany(MasterAbsensi::class, 'master_bimbingan_id', 'id');
    }
}
