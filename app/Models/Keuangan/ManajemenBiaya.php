<?php

namespace App\Models\Keuangan;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ManajemenBiaya extends Model
{
    use HasFactory;

    protected $table = 'keuangan.m_manajemen_biaya';

    protected $primaryKey = 'id_manajemen_biaya';

    protected $fillable = [
        'id_komponen_biaya', 'status', 'status_mahasiswa', 'mhs_id', 'tahun_id', 'potongan_persen'
    ];

    public $timestamps = true;
    protected $connection;

    public function __construct()
    {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function m_biaya_per_Mahasiswa()
    {
        return $this->hasMany(BiayaPerMahasiswa::class, 'id_manajemen_biaya', 'id_manajemen_biaya');
    }
}
