<?php

namespace App\Models\Surat_V2;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PengajuanRoleAcc extends Model
{
    use HasFactory;

    protected $table = 'surat_v2.pengajuan_role_acc';
    protected $connection;

    public $primaryKey = 'id';
    protected $fillable = [
        'master_pengajuan_id',
        'role',
        'deleted_at',
        'delete_from_user'
    ];
    public $increment = true;
    public $timestamps = false;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function master_pengajuan() {
        return $this->belongsTo(MasterPengajuan::class, 'master_pengajuan_id', 'id');
    }
}
