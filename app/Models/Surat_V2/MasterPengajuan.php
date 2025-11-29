<?php

namespace App\Models\Surat_V2;

use App\Models\Traits\Surat_V2\MasterPengajuan\HasUserRelations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterPengajuan extends Model
{
    use HasFactory, HasUserRelations;

    protected $table = 'surat_v2.master_pengajuan';
    protected $connection;

    public $primaryKey = 'id';
    protected $hidden = [
        'by_is_mhs_user_id',
        'by_is_dev_user_id',
        'by_is_doswal_user_id',
        'by_is_prodi_user_id',
        'by_is_admin_user_id',
        'by_is_dosen_user_id',
        'by_is_staff_user_id',
        'by_is_wk_user_id',
        'by_is_pimpinan_user_id',
        'by_is_dospem_user_id',
        'by_is_marketing_user_id',
        'by_is_akademik_user_id',
        'by_is_baak_user_id',
        'by_is_secretary_user_id',
        'by_is_bendahara_user_id',
        'by_is_kemahasiswaan_user_id',
        'pilih_surat'
    ];
    protected $fillable = [
        'nama_pengajuan',
        'pilih_surat',
        'is_mhs',
        'is_dev',
        'is_doswal',
        'is_prodi',
        'is_admin',
        'is_dosen',
        'is_staff',
        'is_wk',
        'is_pimpinan',
        'is_dospem',
        'is_marketing',
        'is_akademik',
        'is_baak',
        'is_secretary',
        'is_bendahara',
        'is_kemahasiswaan',
        'by_is_mhs_user_id',
        'by_is_dev_user_id',
        'by_is_doswal_user_id',
        'by_is_prodi_user_id',
        'by_is_admin_user_id',
        'by_is_dosen_user_id',
        'by_is_staff_user_id',
        'by_is_wk_user_id',
        'by_is_pimpinan_user_id',
        'by_is_dospem_user_id',
        'by_is_marketing_user_id',
        'by_is_akademik_user_id',
        'by_is_baak_user_id',
        'by_is_secretary_user_id',
        'by_is_bendahara_user_id',
        'by_is_kemahasiswaan_user_id',
        'for_mhs',
        'for_admin',
        'deleted_at',
        'delete_from_user',
        'minimum_semester'
    ];
    public $increment = true;
    public $timestamps = false;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function scopeGetMasterPengajuan(Builder $query) {
        return $query->with('master_surat', 'pengajuan_pertanyaan')->get();
    }

    public function pengajuan_pertanyaan() {
        return $this->hasMany(PengajuanPertanyaan::class, 'master_pengajuan_id', 'id');
    }

    public function master_surat() {
        return $this->belongsTo(MasterSurat::class, 'pilih_surat', 'id');
    }

    public function pengajuan() {
        return $this->hasMany(Pengajuan::class, 'master_pengajuan_id', 'id');
    }
    
}
