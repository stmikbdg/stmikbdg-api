<?php

namespace App\Http\Controllers\Ujian;

use App\Http\Controllers\Controller;
use App\Models\KRS\MatKulView;
use App\Models\Users\User;
use Illuminate\Http\Request;

class MigrationsController extends Controller
{
    protected $model_user;
    protected $model_mata_kuliah;
    protected $model_mahasiswa;

    public function __construct() {
        $this->model_user = new User();
        $this->model_mata_kuliah = new MatKulView();
    }

    public function migrate_mahasiswa(Request $request) {
        $data = $this->model_user
            ->where('is_mhs', true)
            ->select(['id', 'email'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function migrate_admin(Request $request) {
        $data = $this->model_user
            ->where('is_admin', true)
            ->select(['id', 'email'])->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function migrate_dosen(Request $request) {
        $data = $this->model_user
            ->where('is_dosen', true)
            ->select(['id', 'email'])->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function migrate_prodi(Request $request) {
        $data = $this->model_user
            ->where('is_prodi', true)
            ->select(['id', 'email'])->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function migrate_matakuliah(Request $request) {
        $data = $this->model_mata_kuliah
            ->select(['mk_id', 'kd_mk', 'nm_mk', 'jur_id', 'semester', 'nm_jurusan'])
            ->where('aktif_jur', true)
            ->where('aktif_kur', true)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }
}
