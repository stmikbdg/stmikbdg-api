<?php

namespace App\Http\Controllers\SIKPS;

use App\Http\Controllers\Controller;
use App\Imports\SIKPS\DataKpSkripsiImport;
use App\Models\SIKPS\DataKpSkripsi;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class DataKpSkripsiController extends Controller
{
    protected $model;

    public function __construct() 
    {
        // parent::__construct();
        $this->model = new DataKpSkripsi();
    }

    public function getAll(Request $request){

        if(!auth()->user()->is_dospem || !auth()->user()->is_prodi) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak'
            ], 403);
        }

        $data = $this->model
            ->with('master_bimbingan')
            ->withCount('master_bimbingan')
            ->get();

        $data = $data->map(function ($item) {
            $totalProgress = 0.0;
            $jumlahEntri = 0;

            foreach ($item->master_bimbingan as $bimbingan) {
                $progress = (float) $bimbingan->progress;

                // Hitung hanya jika progress valid
                if ($progress > 0) {
                    $totalProgress += $progress;
                    $jumlahEntri++;
                }
            }

            // Jika ingin anggap maksimal 100% progress itu target akhir
            // atau bisa juga disesuaikan dengan total entri maksimal jika diketahui
            $persentaseKumulatif = round(min($totalProgress, 100), 2);

            $item->total_bimbingan_diajukan = $jumlahEntri;
            $item->total_progress_kumulatif = $totalProgress;
            $item->persentase_kumulatif = $persentaseKumulatif;

            unset($item->master_bimbingan); // opsional

            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }


    public function import(Request $request) {
        try {

            if(auth()->user()->is_mhs) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak'
                ], 403);
            }

            $request->validate([
                'file' => 'required|file|mimes:xlsx,csv,xls'
            ]);

            $import = new DataKpSkripsiImport;
            Excel::import($import, $request->file('file'));

            // Prepare array for bulk insert
            $insertData = $import->rows->map(function($row) {
                // dd($row);
                return [
                    'judul_laporan'   => $row['1'] ?? null,
                    'jenis_laporan'   => $row['2'] ?? null,
                    'nim'             => (string) $row['3'] ?? null,
                    'nama_mahasiswa'  => $row['4'] ?? null,
                    'semester'        => $row['5'] ?? null,
                    'tahun_akademik'  => $row['6'] ?? null,
                    'pembimbing'      => $row['7'] ?? null,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
            })->toArray();
            
            $data = $this->model->insert($insertData);

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

    public function create(Request $request) {
        try {

            if(auth()->user()->is_mhs) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak'
                ], 403);
            }

            $request->validate([
                'nim' => 'required|string',
                'nama_mahasiswa' => 'required|string',
                'judul_laporan' => 'required|string',
                'jenis_laporan' => 'required|string|in:Kerja Praktek,Skripsi',
                'semester' => 'required|string|in:Genap,Ganjil',
                'tahun_akademik' => 'required|string',
                'pembimbing' => 'required|string'
            ]);

            $body = $request->only((new DataKpSkripsi)->getFillable());

            $data = DataKpSkripsi::create($body);

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }

    public function getForMahasiswa(Request $request) {
        if(!auth()->user()->is_mhs) {
            return response()->json([
                'success' => false,
                'message' => 'Fitur ini hanya diperuntukan untuk mahasiswa saja.'
            ], 403);
        }

        $user = auth()->user();

        $nim = explode('-', $user->kd_user)[1];

        $data = $this->model
            ->where('nim', $nim)
            ->with('master_bimbingan')
            ->withCount('master_bimbingan')
            ->get();

        $data = $data->map(function ($item) {
            $totalProgress = 0.0;
            $jumlahEntri = 0;

            foreach ($item->master_bimbingan as $bimbingan) {
                $progress = (float) $bimbingan->progress;

                // Hitung hanya jika progress valid
                if ($progress > 0) {
                    $totalProgress += $progress;
                    $jumlahEntri++;
                }
            }

            // Jika ingin anggap maksimal 100% progress itu target akhir
            // atau bisa juga disesuaikan dengan total entri maksimal jika diketahui
            $persentaseKumulatif = round(min($totalProgress, 100), 2);

            $item->total_bimbingan_diajukan = $jumlahEntri;
            $item->total_progress_kumulatif = $totalProgress;
            $item->persentase_kumulatif = $persentaseKumulatif;

            unset($item->master_bimbingan); // opsional

            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function getForMahasiswaByJenis(Request $request, string $jenis) {

        if(!in_array($jenis, ['kp', 'skripsi'])) {
            return response()->json([
                'success' => false,
                'message' => 'Jenis laporan tidak valid.'
            ], 400);
        }

        if(!auth()->user()->is_mhs) {
            return response()->json([
                'success' => false,
                'message' => 'Fitur ini hanya diperuntukan untuk mahasiswa saja.'
            ], 403);
        }

        $user = auth()->user();

        $nim = explode('-', $user->kd_user)[1];

        if($jenis == 'kp') {
            $data = $this->model
                ->where('nim', $nim)
                ->with('master_bimbingan')
                ->withCount('master_bimbingan')
                ->where('jenis_laporan', 'Kerja Praktek')
                ->get();
        } else {
            $data = $this->model
                ->where('nim', $nim)
                ->with('master_bimbingan')
                ->withCount('master_bimbingan')
                ->where('jenis_laporan', 'Skripsi')
                ->get();
        }

        $data = $data->map(function ($item) {
            $totalProgress = 0.0;
            $jumlahEntri = 0;

            foreach ($item->master_bimbingan as $bimbingan) {
                $progress = (float) $bimbingan->progress;

                // Hitung hanya jika progress valid
                if ($progress > 0) {
                    $totalProgress += $progress;
                    $jumlahEntri++;
                }
            }

            // Jika ingin anggap maksimal 100% progress itu target akhir
            // atau bisa juga disesuaikan dengan total entri maksimal jika diketahui
            $persentaseKumulatif = round(min($totalProgress, 100), 2);

            $item->total_bimbingan_diajukan = $jumlahEntri;
            $item->total_progress_kumulatif = $totalProgress;
            $item->persentase_kumulatif = $persentaseKumulatif;

            unset($item->master_bimbingan); // opsional

            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function getForDospem(Request $request) {

        if(!auth()->user()->is_dospem) {
            return response()->json([
                'success' => false,
                'message' => 'Fitur ini hanya diperuntukan untuk dosen pembimbing saja.'
            ], 403);
        }

        $user = $this->getUserAuth();

        // Original and normalized variants
        $originalName = $user->nama_dan_gelar;
        $normalizedName = str_replace(',', '.,', $originalName);
        $normalizedName_inverse = str_replace('.,', ',', $normalizedName);

        $data = $this->model
            ->where('pembimbing', $originalName)
            ->orWhere('pembimbing', $normalizedName)
            ->orWhere('pembimbing', $normalizedName_inverse)
            ->with('master_bimbingan')
            ->withCount('master_bimbingan')
            ->get();

        $data = $data->map(function ($item) {
            $totalProgress = 0.0;
            $jumlahEntri = 0;

            foreach ($item->master_bimbingan as $bimbingan) {
                $progress = (float) $bimbingan->progress;

                // Hitung hanya jika progress valid
                if ($progress > 0) {
                    $totalProgress += $progress;
                    $jumlahEntri++;
                }
            }

            // Jika ingin anggap maksimal 100% progress itu target akhir
            // atau bisa juga disesuaikan dengan total entri maksimal jika diketahui
            $persentaseKumulatif = round(min($totalProgress, 100), 2);

            $item->total_bimbingan_diajukan = $jumlahEntri;
            $item->total_progress_kumulatif = $totalProgress;
            $item->persentase_kumulatif = $persentaseKumulatif;

            unset($item->master_bimbingan); // opsional

            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function getByNIM(Request $request, string $nim) {
        if(auth()->user()->is_mhs) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak'
            ], 403);
        }

        $data = $this->model->where('nim', $nim)->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function update(Request $request, int $id) {
        try {

            if(auth()->user()->is_mhs) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak'
                ], 403);
            }

            $data = DataKpSkripsi::find($id);

            if(!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan'
                ], 404);
            }

            $request->validate([
                'nim' => 'required|string',
                'nama_mahasiswa' => 'required|string',
                'judul_laporan' => 'required|string',
                'jenis_laporan' => 'required|string|in:Kerja Praktek,Skripsi',
                'semester' => 'required|string|in:Genap,Ganjil',
                'tahun_akademik' => 'required|string',
                'pembimbing' => 'required|string'
            ]);

            $body = $request->only((new DataKpSkripsi)->getFillable());

            $data->update($body);

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }

    public function delete(Request $request, int $id) {
        try {

            if(auth()->user()->is_mhs) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak'
                ], 403);
            }

            $data = DataKpSkripsi::find($id);

            if(!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan'
                ], 404);
            }

            $data->delete();

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }
}
