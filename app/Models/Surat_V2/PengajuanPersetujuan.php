<?php

namespace App\Models\Surat_V2;

use App\Models\Users\User;
use App\Models\Users\UserView;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PengajuanPersetujuan extends Model
{
    use HasFactory;

    protected $table = 'surat_v2.pengajuan_persetujuan';
    protected $connection;

    public $primaryKey = 'id';
    protected $fillable = [
        'pengajuan_id',
        'status',
        'user_id',
        'komentar',
        'status',
        'verified_at',
        'value',
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
        'deleted_at',
        'delete_from_user'
    ];
    public $increment = true;
    public $timestamps = false;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function pengajuan() {
        return $this->belongsTo(Pengajuan::class, 'pengajuan_id', 'id');
    }

    public function user() {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }


}
