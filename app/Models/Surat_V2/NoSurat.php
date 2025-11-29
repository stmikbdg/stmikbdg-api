<?php

namespace App\Models\Surat_V2;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NoSurat extends Model
{
    use HasFactory;

    protected $table = 'surat_v2.nomor_surat';
    protected $connection;

    public $primaryKey = 'id';
    protected $fillable = [
        'pengajuan_id',
        'nomor_surat'
    ];
    public $increment = true;
    public $timestamps = false;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function pengajuan() {
        return $this->belongsTo(Pengajuan::class, 'pengajuan_id', 'id');
    }
}
