<?php

namespace App\Models\Keuangan;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BiayaPerMahasiswa extends Model
{
    use HasFactory;

    protected $connection;

    protected $table = 'keuangan.m_biaya_per_mahasiswa';

    protected $primaryKey = 'id_biaya_per_mahasiswa';

    protected $fillable = [
        'id_manajemen_biaya', 'potongan_persen', 'potongan_beasiswa', 'jumlah', 'sisa', 'status', 'id_komponen_biaya', 'ket', 'tahun_id'
    ];

    public $timestamps = true;

    

    public function __construct()
    {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function m_komponen_biaya()
    {
        return $this->belongsTo(KomponenBiaya::class, 'id_komponen_biaya', 'id_komponen');
    }
}
