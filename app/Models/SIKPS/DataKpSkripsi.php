<?php

namespace App\Models\SIKPS;

use App\Models\Sidang\Pengajuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SIKPS\MasterJadwal;
use App\Models\SIKPS\MasterBimbingan;

class DataKpSkripsi extends Model
{
    use HasFactory;

    protected $table = 'sikps.data_kp_skripsi';
    protected $connection;

    public $primaryKey = 'id';
    public $fillable = [
        'nim',
        'nama_mahasiswa',
        'judul_laporan',
        'jenis_laporan',
        'semester',
        'tahun_akademik',
        'pembimbing'
    ];
    public $increment = true;
    public $timestamps = true;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function scopeGetAll(Builder $query, array $filters = []){
        foreach ($filters as $key => $value) {
            if (in_array($key, $this->fillable)) {
                $query->where($key, $value);
            }
        }
    
        return $query->get();
    }

    public function master_jadwal() {
        return $this->hasMany(MasterJadwal::class, 'data_kp_skripsi_id', 'id');
    }

    public function master_bimbingan() {
        return $this->hasMany(MasterBimbingan::class, 'data_kp_skripsi_id', 'id');
    }

    public function pendaftaran_sidang() {
        return $this->hasMany(Pengajuan::class, 'id_kp_skripsi', 'id');
    }
}
