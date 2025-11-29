<?php

namespace App\Models\SIKPS;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterAbsensi extends Model
{
    use HasFactory;

    protected $table = 'sikps.master_absensi';
    protected $connection;

    public $primaryKey = 'id';
    protected $fillable = [
        'jadwal_id',
        'status',
        'catatan',
        'is_arsip',
        'master_bimbingan_id',
        'topik_bimbingan'
    ];
    public $increment = true;
    public $timestamps = true;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function master_bimbingan() {
        return $this->belongsTo(MasterBimbingan::class, 'master_bimbingan_id', 'id');
    }

    public function master_jadwal() {
        return $this->belongsTo(MasterJadwal::class, 'jadwal_id', 'id');
    }
}
