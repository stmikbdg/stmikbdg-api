<?php

namespace App\Http\Controllers\Pengumuman;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\KampusView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

// ? Models - Views
use App\Models\KelasKuliah\KelasKuliahJoinView;
use App\Models\Users\DosenView;
use App\Models\TahunAjaranView;

// ? Models - Tables
use App\Models\Perkuliahan\Pengumuman;
use App\Models\KRS\KRSMatkul;
use App\Models\Users\Mahasiswa;
use App\Models\KRS\KRS;
use App\Models\Perkuliahan\FCMClients;

class MahasiswaController extends Controller
{
    public function getListPengumuman(Request $request) {
        try {
            $page = $request->query('page');
            $nim = explode('-', auth()->user()->kd_user)[1];
            $lastKrs = Mahasiswa::where('nim', $nim)
                ->select('mhs_id', 'krs_id_last')
                ->first();

            // get pengumuman by satu matkul
            $kelasKuliahId = $request->query('kelas_kuliah_id');

            if ($kelasKuliahId) {
                // cek krs by tahun ajaran aktif
                $tahunAjaran = TahunAjaranView::getTahunAjaran($this->getUserAuth());
                $krsTahunNow = KRS::where('tahun_id', $tahunAjaran['tahun_id'])->first();

                if (!$krsTahunNow) {
                    return $this->failedResponseJSON(
                        ('Pengumuman untuk mata kuliah milik Anda belum tersedia.'
                        . 'Pastikan KRS di tahun aktif saat ini telah disetujui')
                        , 400
                    );
                }

                $kelasKuliahIds = KRSMatkul::where('krs_id', $lastKrs['krs_id_last'])
                    ->where('kelas_kuliah_id', (int) $kelasKuliahId)
                    ->select('krs_mk_id', 'krs_id', 'kelas_kuliah_id')
                    ->pluck('kelas_kuliah_id')
                    ->toArray();

                if (count($kelasKuliahIds) < 1) {
                    return $this->failedResponseJSON('Kelas kuliah id tidak ditemukan');
                }
            } else {
                $kelasKuliahIdArr = KRSMatkul::with('matakuliah')
                    ->where('krs_id', $lastKrs['krs_id_last'])
                    // ->select('krs_mk_id', 'krs_id', 'kelas_kuliah_id')
                    // ->pluck('kelas_kuliah_id')
                    ->get();
                // array_push($kelasKuliahIdArr, 0); // ambil pengumuman yang ditujukan untuk semua
                $kelasKuliah = $kelasKuliahIdArr;
                $kelasKuliahIds = [];
                foreach ($kelasKuliah as $item) {
                    array_push($kelasKuliahIds, $item['kelas_kuliah_id']);
                }
                array_push($kelasKuliahIds, 0);
            }
           
            $listPengumuman = Pengumuman::whereIn('target', $kelasKuliahIds)
                ->orderBy('tgl_dikirim', 'DESC')
                ->get();

            if(!$kelasKuliahId) {
                foreach ($listPengumuman as $item) {

                    $matching_kelas_kuliah_id = null;
                    foreach ($kelasKuliah as $kelas) {
                        if ($kelas['kelas_kuliah_id'] == $item['target']) {
                            $matching_kelas_kuliah_id = $kelas['kelas_kuliah_id'];
                            break;
                        }
                    }
    
                    if ($matching_kelas_kuliah_id) {
                        $item['keterangan_target'] = 'Pengumuman untuk kelas ' . $kelas['matakuliah']['nm_mk'];
                        $item['matakuliah'] = $kelas['matakuliah']['nm_mk'];
                    }else{
                        $item['keterangan_target'] = 'Pengumuman Umum';
                        $item['matakuliah'] = null;
                    }
                }
            }

            if ($page) {
                $perPage = 5;
                $currentPage = (integer) $page ?? Paginator::resolveCurrentPage();
                $currentPageData = Collection::make($listPengumuman)->slice(($currentPage - 1) * $perPage, $perPage);
                $paginator = new Paginator($currentPageData->all(), $perPage, $currentPage);
                $paginatedData = array_values($paginator->items());
                $totalNextItems = count($listPengumuman) - ($currentPage == 1
                    ? $currentPageData->count()
                    : $currentPageData->count() + ($perPage * $currentPage)
                );

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'list_oengumuman' => $paginatedData,
                    ],
                    'meta' => [
                        'current_page' => $currentPage,
                        'total_items' => count($listPengumuman),
                        'items_per_page' => $paginator->perPage(),
                        'prev_page_url' => $currentPage == 1 ? null
                            :  config('app.url') . 'api/pengumuman/mahasiswa/list' . substr($paginator->previousPageUrl(), 1),
                        'next_page_url' => ($totalNextItems > -1 and count($listPengumuman) > $perPage)
                            ? config('app.url') . 'api/pengumuman/mahasiswa/list?page=' . $currentPage + 1
                            : null,
                    ],
                ], 200);
            }

            return $this->successfulResponseJSON([
                'list_pengumuman' => $listPengumuman
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getListKelasKuliah() {
        try {
            // cek krs di tahun ajaran aktif
            $mahasiswa = $this->getUserAuth();
            $tahunAjaran = TahunAjaranView::getTahunAjaran($mahasiswa);

            if(!$tahunAjaran->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Saat ini belum ada tahun ajaran yang sedang aktif!'
                ], 404);
            }
            
            $lastKRS = KRS::where('tahun_id', $tahunAjaran['tahun_id'])
                ->where('mhs_id', $mahasiswa['mhs_id'])
                ->first();

            if ($lastKRS) {
                $jnsMhs = $mahasiswa['jns_mhs'] == 'R' ? 'Reguler'
                    : ($mahasiswa['jns_mhs'] == 'E' ? 'Eksekutif' : 'Karyawan');
                $kampus = KampusView::where('kd_kampus', $mahasiswa['kd_kampus'])
                    ->select('kd_kampus', 'lokasi')
                    ->first();

                $tempKelasKuliahArr = KRSMatkul::getForPengumumanMahasiswa($lastKRS['krs_id']);

                $kelasKuliahArr = [];

                foreach ($tempKelasKuliahArr as $item) {
                    if (!is_null($item['matakuliah'])) {
                        // if ($item['kelasKuliahJoin']['kjoin_kelas'] and is_null($item['kelasKuliahJoin']['pengajar_id'])) {
                        //     $kelasKuliah = KelasKuliahJoinView::where(
                        //         'kelas_kuliah_id', $item['kelasKuliahJoin']['join_kelas_kuliah_id']
                        //         )->select('kelas_kuliah_id', 'join_kelas_kuliah_id', 'pengajar_id', 'mk_id', 'kd_mk')
                        //         ->with('dosen')
                        //         ->first();
                        //     // $pengajar = [
                        //     //     'nm_dosen' => trim($kelasKuliah['dosen']['nm_dosen']),
                        //     //     'gelar' => trim($kelasKuliah['dosen']['gelar'])
                        //     // ];
                        // } else {
                        //     $dosen = DosenView::where('dosen_id', $item['kelasKuliahJoin']['pengajar_id'])
                        //         ->select('dosen_id', 'nm_dosen', 'gelar')
                        //         ->first();
                            
                        //     // $pengajar = [
                        //     //     'nm_dosen' => trim($dosen['nm_dosen']),
                        //     //     'gelar' => trim($dosen['gelar'])
                        //     // ];
                        // }

                        $kelas = [
                            'kelas_kuliah_id' => $item['kelasKuliahJoin']['kelas_kuliah_id'],
                            'kd_kampus' => $kampus['kd_kampus'],
                            'kampus' => $kampus['lokasi'],
                            'jns_mhs' => $jnsMhs,
                            'mata_kuliah' => [
                                'mk_id' => $item['matakuliah']['mk_id'],
                                'kd_mk' => trim($item['matakuliah']['kd_mk']),
                                'nm_mk' => trim($item['matakuliah']['nm_mk']),
                                'semester' => $item['matakuliah']['semester'],
                                'sks' => $item['matakuliah']['sks'],
                            ],
                            // 'dosen' => $pengajar
                        ];

                        array_push($kelasKuliahArr, $kelas);
                    }
                }

                return $this->successfulResponseJSON([
                    'list_kelas' => $kelasKuliahArr
                ]);
            }

            return $this->failedResponseJSON('Pastikan KRS di tahun ajaran saat ini telah disetujui', 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'message' => $e->getMessage(),
                'debug' => [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTrace()
                ]
            ], 500);
        }
    }

    public function registerToken(Request $request) {
        try {
            $request->validate([
                'token' => 'required|string'
            ]);

            $mahasiswa = $this->getUserAuth();

            $data['client_token'] = $request->token;
            $data['mhs_id'] = $mahasiswa['mhs_id'];
            $data['sts_mhs'] = $mahasiswa['sts_mhs'];

            DB::beginTransaction();

            /**
             * jika mahasiswa telah memiliki fcm token
             * maka hapus yang lama
             */
            $tokenExists = FCMClients::where('mhs_id', $mahasiswa['mhs_id'])->first();

            if ($tokenExists) {
                FCMClients::where('mhs_id', $mahasiswa['mhs_id'])->delete();
            }

            $insert = FCMClients::insert($data);

            if ($insert) {
                DB::commit();

                return $this->successfulResponseJSONV2('Token berhasil disimpan', 200);
            }

            DB::rollBack();

            return $this->failedResponseJSON('Token gagal disimpan', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return ErrorHandler::handle($e);
        }
    }

    public function checkFCMToken() {
        try {
            $mahasiswa = $this->getUserAuth();
            $token = FCMClients::where('mhs_id', $mahasiswa['mhs_id'])->first();

            if ($token) {
                return $this->successfulResponseJSON([
                    'token' => $token['client_token']
                ]);
            }

            return response()->json([
                'status' => 'failed',
                'message' => 'FCM token tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
