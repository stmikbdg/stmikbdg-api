<?php

namespace App\Models\Users;

use App\Models\JurusanView;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

// ? Models - tabel
use App\Models\KRS\KRS;
use App\Models\Users\Dosen;

class Mahasiswa extends Model
{
    /**
     * Model ini digunakan untuk tabel mahasiswa
     * untuk melakukan proses-proses Create, Update, Delete.
     *
     * Koneksi database terhubung ke 'stmikbdg_dummy' tabel mahasiswa.
     *
     * Saat ini masih menggunakan dua database berbeda sehingga
     * belum mendukung relasi antar tabel mahasiswa->users.
     */
    use HasFactory;

    protected $table = 'mahasiswa';
    protected $connection;

    public $timestamps = false;
    protected $guarded = ['id'];

    public function __construct() {
        $this->connection = config('myconfig.database.second_connection');
    }

    public function scopeGetAllMahasiswa(Builder $query, array $filter = []) {

        $query->where('sts_mhs', 'A')
        //   ->where('kd_kampus', 'A')
          // ->whereNotNull('krs_id_last')
          ->with('jurusan');

        // Apply dynamic filters
        foreach ($filter as $key => $value) {
            if (is_array($value)) {
                $query->whereIn($key, $value);
            } elseif (!is_null($value) && $value !== '') {
                $query->where($key, $value);
            }
        }

        return $query->get();
    }

    public function scopeGetAllUniqueByColumns(Builder $query, array $columns = []) {
        return $query->select($columns)->orderBy($columns[0], 'asc')->distinct()->get();
    }

    public function scopeSearchMahasiswa(Builder $query, $filter, $search = null, $page = null) {
        if ($search)  {
            return $query->where('dosen_id', $filter['dosen_id'])
                ->where('sts_mhs', 'A')
                ->where('krs_id_last', '!=', null)
                ->where('nm_mhs', 'like', '%' . strtoupper($search) . '%')
                ->orWhere('nim', 'like', '%' . strtoupper($search) . '%')
                ->get();
        }

        if ($page) {
            $perPage = 10;
            $currentPage = $page ?? 1;

            return $query->where('dosen_id', $filter['dosen_id'])
                ->where('sts_mhs', 'A')
                ->where('krs_id_last', '!=', null)
                ->orderBy('krs_id_last', 'DESC')
                ->paginate($perPage, ['*'], 'page', $currentPage);
        }

        return $query->where('dosen_id', $filter['dosen_id'])
            ->where('sts_mhs', 'A')
            ->where('krs_id_last', '!=', null)
            ->orderBy('krs_id_last', 'DESC')
            ->get();
    }

    public function scopeGetMahasiswaByFilter(Builder $query, $filter) {
        if (isset($filter['skripsi']) and isset($filter['tahun_masuk'])) {
            return $query->where('judul_skripsi', '!=', null)
                ->where('masuk_tahun', $filter['tahun_masuk'])
                ->select('mhs_id', 'nim', 'nm_mhs', 'judul_skripsi', 'predikat')
                ->orderBy('masuk_tahun', 'DESC')
                ->get();
        }

        if (isset($filter['skripsi'])) {
            return $query->where('judul_skripsi', '!=', null)
                ->select('mhs_id', 'nim', 'nm_mhs', 'judul_skripsi', 'predikat')
                ->orderBy('masuk_tahun', 'DESC')
                ->get();
        }

        if (isset($filter['tahun_masuk'])) {
            return $query->where('masuk_tahun', $filter['tahun_masuk'])
                ->select('mhs_id', 'nim', 'nm_mhs', 'judul_skripsi', 'predikat')
                ->orderBy('masuk_tahun', 'DESC')
                ->get();
        }
    }

    /**
     * Relasi tabel mahasiswa ke krs, one to many
     */
    public function krs() {
        return $this->hasMany(KRS::class, 'krs_id', 'krs_id_last');
    }

    /**
     * Relasi tabel mahasiswa ke dosen, many to one
     */
    public function dosen() {
        return $this->belongsTo(Dosen::class, 'dosen_id', 'dosen_id');
    }

    /**
     * Relasi tabel mahasiswa ke v jurusan, many to one
     */
    public function jurusan() {
        return $this->belongsTo(JurusanView::class, 'jur_id', 'jur_id');
    }

}
