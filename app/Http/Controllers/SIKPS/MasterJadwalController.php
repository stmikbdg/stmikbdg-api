<?php

namespace App\Http\Controllers\SIKPS;

use App\Http\Controllers\Controller;
use App\Models\SIKPS\DataKpSkripsi;
use App\Models\SIKPS\MasterBimbingan;
use App\Models\SIKPS\MasterJadwal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MasterJadwalController extends Controller
{
    protected $model;
    public function __construct()
    {
        $this->model = new MasterJadwal();
    }

    private function debug_data(array $data = []) {
        return response()->json($data, 200);
    }

    public function getAll(Request $request) {
        if(!auth()->user()->is_dospem) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak, hanya untuk dosen pembimbing saja.'
            ], 403);
        }

        $dospem = $this->getUserAuth();

        $data = $this->model->with('master_absensi')
            ->where('pembimbing', 'like', '%'.$dospem->nama.'%')
            ->get()
            ->map(function ($item) {
                $item['status'] = $item->master_absensi->isEmpty() ? 'tersedia' : 'terisi';

                // Remove the relation from the output
                unset($item->master_absensi); // Option 1: Unset it directly

                return $item;
            });

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function getAll_mahasiswa(Request $request) {
        $user = $this->getUserAuth();

        $master_bimbingan = MasterBimbingan::whereHas('data_kp_skripsi', function ($query) use ($user) {
                $query->where('nim', $user->nim);
            })
            ->with('data_kp_skripsi')
            // ->pluck('id');
            ->get();

        $pembimbing = [];
        $jenis_jadwal = [];

        foreach ($master_bimbingan as $bimbingan) {
            $data_kp_skripsi = $bimbingan['data_kp_skripsi'] ?? null;

            if(!$data_kp_skripsi) {
                continue;
            }

            $pembimbing_data = $data_kp_skripsi['pembimbing'] ?? null;
            $jenis_laporan_data = $data_kp_skripsi['jenis_laporan'] ?? null;

            if ($pembimbing_data && !in_array($pembimbing_data, $pembimbing)) {
                $pembimbing[] = $pembimbing_data;
            }

            if ($jenis_laporan_data && !in_array($jenis_laporan_data, $jenis_jadwal)) {
                $jenis_jadwal[] = $jenis_laporan_data;
            }
        }

        $now = Carbon::now();
        $today = $now->toDateString();

        $data = $this->model
            // ->whereHas('master_absensi.master_bimbingan', function ($query) use ($master_bimbingan_ids) {
            //     $query->whereIn('master_absensi.master_bimbingan_id', $master_bimbingan_ids);        
            // })
            ->whereDate('waktu_jadwal', '>=', $today)
            ->whereIn('pembimbing', $pembimbing)
            ->whereIn('jenis_jadwal', $jenis_jadwal)
            ->with('master_absensi.master_bimbingan')
            ->get()
            ->map(function ($item) {
                $item['status'] = $item->master_absensi->isEmpty() ? 'tersedia' : 'terisi';

                // Remove the relation from the output
                unset($item->master_absensi); // Option 1: Unset it directly

                return $item;
            });

        return response()->json([
            'success' => true,
            'data' => $data,
            // 'pembimbing' => $pembimbing,
            // 'jenis_jadwal' => $jenis_jadwal,
            // 'master_bimbingan' => $master_bimbingan
        ]);
    }

    public function create(Request $request) {
        try {

            if(!auth()->user()->is_dospem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak, hanya untuk dosen pembimbing saja.'
                ], 403);
            }

            $request->validate([
                'waktu_jadwal' => 'date|required',
                'jenis_jadwal' => 'required|string|in:Skripsi,Kerja Praktek',
                'jam_mulai' => 'required|string',
                'jam_selesai' => 'required|string'
            ]);

            // Cek apakah dospem memiliki data kp skripsi
            $dospem = $this->getUserAuth();

            $data_kp_skripsi = DataKpSkripsi::where('pembimbing', 'like', '%'.$dospem->nama.'%')
                // ->where('jenis_laporan', '')
                ->first();

            if(!$data_kp_skripsi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nama anda tidak ditemukan sebagai pembimbing dalam Data KP dan Skripsi'
                ], 404);
            }

            $body = $request->only((new MasterJadwal)->getFillable());

            $body['pembimbing'] = $data_kp_skripsi['pembimbing'];

            $data = $this->model->create($body);

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ], 500);
        }
    }

    public function update(Request $request, int $id) {
        try {

            if(!auth()->user()->is_dospem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak, hanya untuk dosen pembimbing saja.'
                ], 403);
            }

            $dospem = $this->getUserAuth();

            $data = $this->model
                ->where('id', $id)
                ->where('pembimbing', 'like', '%'.$dospem->nama.'%')->first();

            if(!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan'
                ], 404);
            }

            $request->validate([
                'waktu_jadwal' => 'date|required',
                'jenis_jadwal' => 'required|string|in:Skripsi,Kerja Praktek',
                'jam_mulai' => 'required|string',
                'jam_selesai' => 'required|string'
            ]);

            // Cek apakah dospem memiliki data kp skripsi
            

            $data_kp_skripsi = DataKpSkripsi::where('pembimbing', 'like', '%'.$dospem->nama.'%')
                // ->where('jenis_laporan', '')
                ->first();

            if(!$data_kp_skripsi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nama anda tidak ditemukan sebagai pembimbing dalam Data KP dan Skripsi'
                ], 404);
            }

            $body = $request->only((new MasterJadwal)->getFillable());

            $body['pembimbing'] = $data_kp_skripsi['pembimbing'];

            $data->update($body);

            if(!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data gagal diupdate'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ], 400);
        }
    }

    public function delete(Request $request, int $id) {
        try {

            if(!auth()->user()->is_dospem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak, hanya untuk dosen pembimbing saja.'
                ], 403);
            }

            $dospem = $this->getUserAuth();

            $data = $this->model
                ->where('id', $id)
                ->where('pembimbing', 'like', '%'.$dospem->nama.'%')->first();

            if(!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan'
                ], 404);
            }

            $data->delete();

            if(!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data gagal dihapus'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ], 400);
        }
    }

    
}
