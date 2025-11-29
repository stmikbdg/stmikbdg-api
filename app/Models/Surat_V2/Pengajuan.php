<?php

namespace App\Models\Surat_V2;

use App\Models\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pengajuan extends Model
{
    use HasFactory;

    protected $table = 'surat_v2.pengajuan_mahasiswa';
    protected $connection;

    public $primaryKey = 'id';
    protected $fillable = [
        'user_id',
        'master_pengajuan_id',
        'deleted_at',
        'delete_from_user'
    ];

    public $increment = true;
    public $timestamps = true;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function scopeGetAll(Builder $query) {
        return $query->with('master_pengajuan.master_surat', 'user', 'pengajuan_jawaban.pengajuan_pertanyaan', 'nomor_surat')->get();
    }

    public function scopeGetById(Builder $query, int $id) {
        return $query->with('master_pengajuan.master_surat', 'pengajuan_persetujuan', 'user', 'pengajuan_jawaban.pengajuan_pertanyaan', 'nomor_surat')
            ->where('id', $id)
            ->get();
    }

    public function master_pengajuan() {
        return $this->belongsTo(MasterPengajuan::class, 'master_pengajuan_id', 'id');
    }

    public function user() {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function pengajuan_jawaban() {
        return $this->hasMany(PengajuanJawaban::class, 'pengajuan_id', 'id');
    }

    public function pengajuan_persetujuan() {
        return $this->hasMany(PengajuanPersetujuan::class, 'pengajuan_id', 'id');
    }

    public function nomor_surat() {
        return $this->hasOne(NoSurat::class, 'pengajuan_id', 'id');
    }
}
