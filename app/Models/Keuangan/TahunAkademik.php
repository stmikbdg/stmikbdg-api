<?php

namespace App\Models\Keuangan;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class TahunAkademik extends Model
{
    use HasFactory;

    protected $table = 'keuangan.m_thn_akademik';
    protected $connection;

    public $primaryKey = 'id_thn_akademik';
    protected $fillable = [
        'thn_akademik',
        'ganjil_mulai',
        'ganjil_akhir',
        'genap_mulai',
        'genap_akhir',
        'antara_mulai',
        'antara_akhir',
        'ganjil_pelaksanaan_mulai',
        'ganjil_pelaksanaan_akhir',
        'genap_pelaksanaan_mulai',
        'genap_pelaksanaan_akhir',
        'antara_pelaksanaan_mulai',
        'antara_pelaksanaan_akhir',
        'status',
        'termin'
    ];
    public $increment = true;
    public $timestamps = true;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function scopeGetTahunAkademik(Builder $query, array $filters = []) {
        // $record = DB::table('keuangan.m_tahun_akademi')->first();

        // return $record;

        foreach ($filters as $key => $value) {
            if (in_array($key, $this->fillable)) {
                $query->where($key, $value);
            }
        }
    
        return $query->get();
    }
}
