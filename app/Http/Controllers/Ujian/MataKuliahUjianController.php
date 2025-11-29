<?php

namespace App\Http\Controllers\Ujian;

use App\Http\Controllers\Controller;
use App\Models\KelasKuliah\KelasKuliahJoinView;
use App\Models\KRS\KRS;
use App\Models\KRS\KRSMatkul;
use App\Models\TahunAjaranView;
use Illuminate\Http\Request;

class MataKuliahUjianController extends Controller
{
    protected $kelas_kuliah;

    public function __construct() {
        $this->kelas_kuliah = new KelasKuliahJoinView();
    }

    public function getAll_kelas_kuliah(Request $request) {
        $filters = $this->parseFilters($request->query('filters') ?? []);

        if(!isset($filters['kelas_kuliah_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Anda perlu menambahkan query kelas_kuliah_id'
            ], 404);
        }

        $data = $this->kelas_kuliah->whereIn('kelas_kuliah_id', (array) $filters['kelas_kuliah_id'])->with('matakuliah', 'dosen')->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function getAll_mata_kuliah_mahasiswa(Request $request) {
        try {
            // $filterHari = $request->query('hari');
            $mahasiswa = $this->getUserAuth();
            $tahunAjaranAktif = TahunAjaranView::getTahunAjaran($mahasiswa);
            $lastKRS = KRS::where('tahun_id', $tahunAjaranAktif['tahun_id'])
                ->where('mhs_id', $mahasiswa['mhs_id'])
                ->first();

            if ($lastKRS) {
                if ($lastKRS['sts_krs'] === 'S') {
                    $krsMatkul = KRSMatkul::getKRSMatkulWithKelasKuliah($lastKRS['krs_id'])->toArray();
                    $kelasKuliah = array_map(function ($item) {
                        return $item['kelas_kuliah_join'];
                    }, $krsMatkul);

                    $data = [];

                    if (isset($kelasKuliah[0]['kelas_kuliah_id'])) {
                        foreach ($kelasKuliah as $index => $item) {

                            $data[] = $formattedItem = [
                                'data_kelas' => [
                                    'kelas_kuliah_id' => $item['kelas_kuliah_id'],
                                    'tahun_id' => $item['tahun_id'],
                                    'jur_id' => $item['jur_id'],
                                    'mk_id' => $item['mk_id'],
                                    'join_kelas_kuliah_id' => $item['join_kelas_kuliah_id'],
                                    'kjoin_kelas' => $item['kjoin_kelas'],
                                    'kelas_kuliah' => $item['kelas_kuliah'],
                                    'jns_mhs' => $item['jns_mhs'],
                                    'sts_kelas' => $item['sts_kelas'],
                                    'pengajar_id' => $item['pengajar_id'],
                                    'join_jur' => $item['join_jur'],
                                ],
                                'dosen' => $item['dosen'],
                                'matakuliah' => $item['matakuliah'],
                                'riwayat_presensi_maks' => 20, // sementara, untuk menentukan maksimal presensi atau pertemuan kelas,
                            ];
                        }

                        // semua jadwal
                        return $this->successfulResponseJSON([
                            'kelas_kuliah' => $data
                        ]);
                    }
                }

                return response()->json([
                    'status' => 'fail',
                    'message' => 'Status KRS belum mengajukan atau masih dalam tahap pengajuan'
                ], 404);
            }

            return $this->failedResponseJSON('Kelas kuliah tidak ditemukan', 404);
        } catch (\Exception $e) {
            return $this->failedResponseJSON($e->getMessage(), 500);
        }
    }

    public function getAll_mata_kuliah_dosen(Request $request) {
        try {
            $filterHari = $request->query('hari');
            $dosen = $this->getUserAuth();
            $allTahunAjaranAktif = TahunAjaranView::all()
                ->pluck('tahun_id');

            // get all kelas kuliah by tahun ajaran aktif and dosen id
            $kelasKuliah = KelasKuliahJoinView::getKelasKuliahByDosen($allTahunAjaranAktif, $dosen['dosen_id']);
            $kelasJoinIdArr = $kelasKuliah
                ->filter(function ($item) {
                    return $item['kjoin_kelas'];
                })
                ->pluck('kelas_kuliah_id');

            $filteredKelasKuliah = $kelasKuliah->whereNotIn('kelas_kuliah_id', $kelasJoinIdArr)
                ->flatten();

            $formattedItem = [];
            // get setiap jadwal
            foreach ($filteredKelasKuliah as $index => $item) {

                $formattedItem[] = [
                    'data_kelas' => [
                        'kelas_kuliah_id' => $item['kelas_kuliah_id'],
                        'tahun_id' => $item['tahun_id'],
                        'jur_id' => $item['jur_id'],
                        'mk_id' => $item['mk_id'],
                        'join_kelas_kuliah_id' => $item['join_kelas_kuliah_id'],
                        'kjoin_kelas' => $item['kjoin_kelas'],
                        'kelas_kuliah' => $item['kelas_kuliah'],
                        'jns_mhs' => $item['jns_mhs'],
                        'sts_kelas' => $item['sts_kelas'],
                        'pengajar_id' => $item['pengajar_id'],
                        'join_jur' => $item['join_jur'],
                    ],
                    'matakuliah' => $item['matakuliah']
                ];
            }

            // semua jadwal
            return $this->successfulResponseJSON([
                'kelas_kuliah' => $formattedItem,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e 
            ]);
        }
    }

    public function get_mata_kuliah_by_mk_id(Request $request, int $mk_id) {
        try {
            $filterHari = $request->query('hari');
            $dosen = $this->getUserAuth();
            $allTahunAjaranAktif = TahunAjaranView::all()
                ->pluck('tahun_id');

            // get all kelas kuliah by tahun ajaran aktif and dosen id
            $kelasKuliah = KelasKuliahJoinView::getKelasKuliahByDosen($allTahunAjaranAktif, $dosen['dosen_id']);
            $kelasJoinIdArr = $kelasKuliah
                ->filter(function ($item) {
                    return $item['kjoin_kelas'];
                })
                ->pluck('kelas_kuliah_id');

            $filteredKelasKuliah = $kelasKuliah->whereNotIn('kelas_kuliah_id', $kelasJoinIdArr)
                ->flatten();

            $filteredKelasKuliah = $filteredKelasKuliah->where('mk_id', $mk_id)->first();

            return response()->json([
                'success' => true,
                'data' => $filteredKelasKuliah
            ]);

            $formattedItem = [];
            // get setiap jadwal
            foreach ($filteredKelasKuliah as $index => $item) {

                $formattedItem[] = [
                    'data_kelas' => [
                        'kelas_kuliah_id' => $item['kelas_kuliah_id'],
                        'tahun_id' => $item['tahun_id'],
                        'jur_id' => $item['jur_id'],
                        'mk_id' => $item['mk_id'],
                        'join_kelas_kuliah_id' => $item['join_kelas_kuliah_id'],
                        'kjoin_kelas' => $item['kjoin_kelas'],
                        'kelas_kuliah' => $item['kelas_kuliah'],
                        'jns_mhs' => $item['jns_mhs'],
                        'sts_kelas' => $item['sts_kelas'],
                        'pengajar_id' => $item['pengajar_id'],
                        'join_jur' => $item['join_jur'],
                    ],
                    'matakuliah' => $item['matakuliah']
                ];
            }

            // semua jadwal
            return $this->successfulResponseJSON([
                'kelas_kuliah' => $formattedItem,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e 
            ]);
        }
    }

    public function count_mahasiswa_by_kelas_kuliah_id(Request $request, int $kelas_kuliah_id) {
        try {
            $user = $this->getUserAuth();

            $data = KRSMatkul::with('krs', 'kelasKuliahJoin')
                ->whereHas('krs', function ($query) use ($kelas_kuliah_id) {
                    $query->where('kelas_kuliah_id', $kelas_kuliah_id);
                })
                ->count();

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e 
            ]);
        }
    }
}
