<?php

namespace App\Models\Surat_V2;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PengajuanPertanyaan extends Model
{
    use HasFactory;

    protected $table = 'surat_v2.pengajuan_pertanyaan';
    protected $connection;

    public $primaryKey = 'id';
    protected $fillable = [
        'master_pengajuan_id',
        'pertanyaan',
        'jenis_input',
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

    public function pengajuan_jawaban() {
        return $this->hasMany(PengajuanJawaban::class, 'pertanyaan_id', 'id');
    }

    
}
