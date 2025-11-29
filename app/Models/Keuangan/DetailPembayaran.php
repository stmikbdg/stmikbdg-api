<?php

namespace App\Models\Keuangan;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailPembayaran extends Model
{
       use HasFactory;


    protected $table = 'keuangan.m_detail_pembayaran';


    protected $primaryKey = 'id_detail_pembayaran';


    protected $fillable = [
        'id_pembayaran', 'id_biaya_per_mahasiswa', 'mhs_id', 'nominal_bayar', 'status_verifikasi', 'is_kp', 'is_skripsi', 'is_lainnya'
    ];


    public $timestamps = true;


    public function pembayaran()
    {
        return $this->belongsTo(MasterPembayaran::class, 'id_pembayaran', 'id_pembayaran');
    }


    public function biayaPerMahasiswa()
    {
        return $this->belongsTo(BiayaPerMahasiswa::class, 'id_biaya_per_mahasiswa', 'id_biaya_per_mahasiswa');
    }

}
