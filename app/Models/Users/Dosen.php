<?php

namespace App\Models\Users;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// ? Models - table

class Dosen extends Model
{
    /**
     * Model ini digunakan untuk tabel dosen
     * untuk melakukan proses-proses Create, Update, Delete.
     *
     * Koneksi database terhubung ke 'stmikbdg_dummy' tabel dosen.
     */
    use HasFactory;

    protected $connection;

    protected $table = 'dosen';

    protected $guarded = ['id'];

    public function __construct()
    {
        $this->connection = config('myconfig.database.second_connection');
    }

    public function scopeGetListKRSMahasiswa(Builder $query, $dosenId, $search = null, $tahunMasuk = null)
    {
        $baseQuery = $query->where('dosen_id', $dosenId)
            ->with(['mahasiswa' => function ($query) use ($search, $tahunMasuk) {
                $mhsQuery = $query->select(
                    'mhs_id', 'nim', 'nm_mhs', 'jns_mhs', 'sts_mhs', 'kd_kampus', 'kelas', 'masuk_tahun', 'dosen_id', 'krs_id_last'
                )
                    ->where('krs_id_last', '!=', null)
                    ->whereNotIn('sts_mhs', ['L', 'N']);

                // search saja
                if ($search and ! $tahunMasuk) {
                    $mhsQuery->where('nm_mhs', 'like', '%'.strtoupper($search).'%')
                        ->orWhere('nim', 'like', '%'.strtoupper($search).'%');
                }

                // tahun masuk saja
                if ($tahunMasuk and ! $search) {
                    $mhsQuery->where('masuk_tahun', $tahunMasuk);
                }

                // search dan tahun masuk
                if ($search and $tahunMasuk) {
                    $mhsQuery->where('masuk_tahun', $tahunMasuk)
                        ->where('nm_mhs', 'like', '%'.strtoupper($search).'%')
                        ->orWhere('nim', 'like', '%'.strtoupper($search).'%');
                }

                $mhsQuery->with(['krs' => function ($query) {
                    $query->select(
                        'krs_id', 'tahun_id', 'mhs_id', 'nmr_krs', 'tanggal', 'semester', 'sts_krs', 'kd_kampus'
                    )->whereIn('sts_krs', ['P', 'D', 'S']);
                }])->orderBy('krs_id_last', 'DESC');
            }]);

        return $baseQuery->get()[0]['mahasiswa'];
    }

    /**
     * Relasi tabel dosen ke mahasiswa, one to many
     */
    public function mahasiswa()
    {
        return $this->hasMany(Mahasiswa::class, 'dosen_id', 'dosen_id');
    }
}
