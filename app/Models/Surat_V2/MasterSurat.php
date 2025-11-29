<?php

namespace App\Models\Surat_V2;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MasterSurat extends Model
{
    use HasFactory;

    protected $table = 'surat_v2.master_surat';
    protected $connection;

    public $primaryKey = 'id';
    protected $fillable = [
        'isi_surat',
        'nama_surat',
        'deleted_at',
        'delete_from_user'
    ];
    public $increment = true;
    public $timestamps = false;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function scopeGetAllMasterSurat(Builder $query) {
        return $query->get();
    }

    public function master_pengajuan() {
        return $this->hasMany(MasterPengajuan::class, 'pilih_surat', 'id');
    }
}
