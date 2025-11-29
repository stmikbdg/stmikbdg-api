<?php

namespace App\Models\Keuangan;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterKomponenBiaya extends Model
{
    use HasFactory;

    protected $table = 'keuangan.m_komponen_biaya';
    

    public $primaryKey = 'id_komponen';
    protected $fillable = [
        'tahun_angkatan',
        'nama_komponen',
        'kewajiban',
        'ket',
        'deleted_at',
        'deleted_from_user',
        'status',
        'jenis_kelas'
    ];
    public $increment = true;
    public $timestamps = true;

    protected $connection;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }
}
