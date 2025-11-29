<?php


namespace App\Models\Keuangan;

use App\Models\Keuangan\DetailPembayaran;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class MasterPembayaran extends Model
{
    use HasFactory;


    protected $table = 'keuangan.m_pembayaran';


    protected $primaryKey = 'id_pembayaran';


    protected $fillable = [
        'mhs_id', 'total_pembayaran', 'tanggal_pembayaran', 'bukti_pembayaran', 'form_penangguhan', 'catatan', 'bank', 'no_transaksi', 'no_kwitansi', 'metode_pembayaran', 'tahun', 'smt', 'tahun_id', 'termin'
    ];


    public $timestamps = true;


    public function detailPembayaran()
    {
        return $this->hasMany(DetailPembayaran::class, 'id_pembayaran', 'id_pembayaran');
    }
}

