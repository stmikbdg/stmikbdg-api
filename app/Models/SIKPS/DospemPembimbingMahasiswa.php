<?php

namespace App\Models\SIKPS;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class DospemPembimbingMahasiswa extends Model
{
    use HasFactory;

    protected $table = 'sikps.dospem_pembimbing_mahasiswa';
    protected $connection;

    public $primaryKey = 'id';
    protected $fillable = [
        'user_id_mhs',
        'user_id_pemb',
        'judul_laporan',
        'jenis_laporan',
        'semester',
        'tahun_ajaran_id'
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

    public function tahun_ajaran() {
        return $this->belongsTo(MasterTahunAkademik::class, 'tahun_ajaran_id', 'id');
    }
}
