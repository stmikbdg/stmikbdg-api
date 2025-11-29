<?php

namespace App\Models\SIKPS;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterJadwal extends Model
{
    use HasFactory;

    protected $table = 'sikps.master_jadwal';
    protected $connection;

    public $primaryKey = 'id';
    protected $fillable = [
        'waktu_jadwal',
        'jenis_jadwal',
        'status',
        'is_arsip',
        'jam_mulai',
        'jam_selesai',
        'pembimbing'
    ];
    public $increment = true;
    public $timestamps = true;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function master_absensi() {
        return $this->hasMany(MasterAbsensi::class, 'jadwal_id', 'id');
    }
}
