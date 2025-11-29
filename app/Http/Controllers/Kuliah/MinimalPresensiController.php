<?php

namespace App\Http\Controllers\Kuliah;

use App\Http\Controllers\Controller;
use App\Models\KelasKuliah\MinimalPresensi;
use App\Models\KRS\MatKulView;
use App\Models\TahunAjaran;
use App\Models\TahunAjaranView;
use Illuminate\Http\Request;

class MinimalPresensiController extends Controller
{

    protected $model_minimal_presensi;


    public function __construct() {
        $this->model_minimal_presensi = new MinimalPresensi();
    }

    public function getAll_admin(Request $request) {
        $data = $this->model_minimal_presensi->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function getSingleByTahunId_admin(Request $request, int $tahun_id) {
        $data = $this->model_minimal_presensi
            ->whereHas('tahun_ajaran', function ($query) use ($tahun_id) {
                $query->where('tahun_id', $tahun_id);
            })
            ->first();

        // if(!$data) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Data tidak ditemukan'
        //     ], 404);
        // }

        return response()->json([
            'success' => true,
            'data' => [
                'persentase' => $data ? $data['persentase'] : 0
            ]
        ]);
    }

    public function create_admin(Request $request) {
        try {
            $request->validate([
                'fk_tahun_ajaran' => 'integer|required',
                'persentase' => 'integer|required|min:0|max:100', // fixed validation syntax
            ]);

            $body = $request->only((new MinimalPresensi)->getFillable());

            // Check Tahun Ajaran exists
            $tahun_ajaran = TahunAjaran::where('tahun_id', $body['fk_tahun_ajaran'])->first();
            if (!$tahun_ajaran) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tahun Ajaran tidak ditemukan'
                ], 404);
            }

            // Create or update
            $minimal_presensi = $this->model_minimal_presensi
                ->updateOrCreate(
                    ['fk_tahun_ajaran' => $body['fk_tahun_ajaran']], // match condition
                    $body // update values
                );

            return response()->json([
                'success' => true,
                'data' => $minimal_presensi,
                'message' => $minimal_presensi->wasRecentlyCreated
                    ? 'Data berhasil dibuat'
                    : 'Data berhasil diperbarui'
            ]);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false, // should be false if error
                'message' => $error->getMessage(),
            ], 500);
        }
    }

    public function update_admin(Request $request, int $id) {
        try {
            $request->validate([
                'fk_tahun_ajaran' => 'integer|required',
                'persentase' => 'integer|required|min:0,max:100'
            ]);

            $body = $request->only((new MinimalPresensi)->getFillable());

            $tahun_ajaran = TahunAjaran::where('tahun_id', $body['fk_tahun_ajaran'])->first();

            if(!$tahun_ajaran) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tahun Ajaran tidak ditemukan'
                ], 404);
            }

            $data = $this->model_minimal_presensi->find($id);

            if(!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan'
                ], 404);
            }

            $data->update($body);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $error) {
            return response()->json([
                'success' => true,
                'message' => $error->getMessage(),
                'debug' => $error
            ], 500);
        }
    }

    public function deleteSingleById_admin(Request $request, int $id) {
        try {

            $data = $this->model_minimal_presensi->find($id);

            if(!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan'
                ], 404);
            }

            $data->update([
                'persentase' => 0
            ]);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $error) {
            return response()->json([
                'success' => true,
                'message' => $error->getMessage(),
                'debug' => $error
            ], 500);
        }
    }

    public function get_mahasiswa(Request $request) {
        // $account = auth()->user();
        $user = $this->getUserAuth();

        // return response()->json([
        //     'success' => true,
        //     'data' => [
        //         'account' => $account,
        //         'user' => $user
        //     ]
        // ]);

        $mahasiswa = $user;
        // $tahunAjaran = TahunAjaranView::getTahunAjaran($mahasiswa);

        $data = $this->model_minimal_presensi
            ->whereHas('tahun_ajaran', function ($query) use ($user) {
                $query->where('jur_id', $user['jur_id'])
                    ->where('jns_mhs', $user['jns_mhs'])
                    ->where('kd_kampus', $user['kd_kampus']);
            })
            // ->with('tahun_ajaran')
            ->first();

        

        // $data = $this->model_minimal_presensi->
        // $matkul = MatKulView::where('mk_id', $mk_id)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'persentase' => $data ? $data['persentase'] : 0
            ]
        ]);
    }
}
