<?php

namespace App\Models\KelasKuliah;

use App\Models\TahunAjaran;
use App\Models\TahunAjaranView;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MinimalPresensi extends Model
{
    use HasFactory;

    protected $table = 'public.minimal_persentase_presensi';
    protected $connection;

    public $primaryKey = 'id';
    public $fillable = [
        'fk_tahun_ajaran',
        'persentase',
        'created_at',
        'updated_at'
    ];
    public $increment = true;
    public $timestamps = true;

    public function __construct() {
        $this->connection = config('myconfig.database.second_connection');
    }

    public function tahun_ajaran() {
        return $this->belongsTo(TahunAjaran::class, 'fk_tahun_ajaran', 'tahun_id');
    }
}
