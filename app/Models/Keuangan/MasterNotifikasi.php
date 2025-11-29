<?php

namespace App\Models\Keuangan;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterNotifikasi extends Model
{
    use HasFactory;


    protected $table = 'keuangan.m_notifikasi';


    protected $primaryKey = 'id_notifikasi';


    protected $fillable = [
        'id_pembayaran', 'title', 'message', 'status'
    ];


    public $timestamps = true;


    public function pembayaran()
    {
        return $this->belongsTo(MasterPembayaran::class, 'id_pembayaran', 'id_pembayaran');
    }

}
