<?php

namespace App\Http\Controllers\Kuliah;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\KelasKuliah\BeritaAcara;
use Illuminate\Http\Request;
use Carbon\Carbon;

// ? Models - view
use App\Models\KelasKuliah\KelasKuliahJoinView;
use App\Models\TahunAjaranView;
use App\Models\KelasKuliah\JadwalView;
use App\Models\KelasKuliah\KontrakKelasKuliah;
// ? Models - table
use App\Models\KelasKuliah\Pertemuan;
use App\Models\KelasKuliah\Presensi;
use App\Models\KRS\KRS;
use App\Models\KRS\KRSMatkul;
use App\Models\KRS\MatKulView;

class KelasKuliahController extends Controller {
    public function getKelasKuliahByDosen(Request $request) {
        try {
            $filterHari = $request->query('hari');
            $dosen = $this->getUserAuth();
            $allTahunAjaranAktif = TahunAjaranView::all()->pluck('tahun_id');

            // get all kelas kuliah by tahun ajaran aktif and dosen id
            $kelasKuliah = KelasKuliahJoinView::getKelasKuliahByDosen($allTahunAjaranAktif, $dosen['dosen_id']);
            $kelasJoinIdArr = $kelasKuliah->filter(function ($item) {
                return $item['kjoin_kelas'];
            })->pluck('kelas_kuliah_id');
            $filteredKelasKuliah = $kelasKuliah->whereNotIn('kelas_kuliah_id', $kelasJoinIdArr)->flatten();
            
            // get setiap jadwal
            foreach ($filteredKelasKuliah as $index => $item) {
                $jadwal = JadwalView::getJadwalKelasKuliah($item['kelas_kuliah_id'], $dosen['dosen_id'], true);
                
                // add by ziyad - nambah kontrak kelas kuliah - ngambil kontrak kelas kuliah berdasarkan kelas kuliah id
                $kontrakKelasKuliah = KontrakKelasKuliah::getKontrakKelasKuliah($item);

                // get riwayat pertemuan
                $riwayatPertemuan = Pertemuan::getRiwayatPertemuanKelasKuliahByDosen($item['kelas_kuliah_id'], $dosen['dosen_id']);

                $formattedItem = [
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
                        // 'kelas_kuliah' => $item
                    ],
                    'dosen' => $item['dosen'],
                    'matakuliah' => $item['matakuliah'],
                    'riwayat_pertemuan' => $riwayatPertemuan,
                    'riwayat_pertemuan_maks' => 20, // sementara, untuk menentukan maksimal pertemuan,
                    'kontrak_kuliah' => $kontrakKelasKuliah->last(),
                    'minimal_presensi' => [
                        'persentase' => $item['tahun_ajaran']['minimal_presensi']
                            ? $item['tahun_ajaran']['minimal_presensi']['persentase']
                            : 0,
                        'is_exist' => $item['tahun_ajaran']['minimal_presensi'] ? true : false 
                    ]
                ];

                // atur response properti kelas dan jadwal
                $filteredKelasKuliah[$index] = self::setKelasKuliahAndJadwalProperties($formattedItem, $jadwal);
            }

            // urutkan berdasarkan nama hari, Senin, Selasa, ... Minggu, Unknown
            $orderedKelasKuliahByNamaHari = self::orderingKelasKuliahByNamaHari($filteredKelasKuliah);

            // terdapat query 'hari'
            if ($filterHari) {
                return self::filterKelasKuliahByHari($orderedKelasKuliahByNamaHari, $filterHari);
            }

            // ubah ke array
            $transformedResponse = [];

            foreach ($orderedKelasKuliahByNamaHari as $key => $item) {
                $transformedResponse[] = [$key => $item];
            }

            // semua jadwal
            return $this->successfulResponseJSON([
                'kelas_kuliah' => $transformedResponse,
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getKelasKuliahByMahasiswa(Request $request) {
        /**
         * Note:
         * - Jika kelas_dibuka true, maka mahasiswa dapat mengirim pin presensi
         */
        try {

            $isDebug = $request->query('debug');
            $debugSection = $request->query('debug_section');

            $filterHari = $request->query('hari');
            $mahasiswa = $this->getUserAuth();
            $tahunAjaranAktif = TahunAjaranView::getTahunAjaran($mahasiswa);

            if(!$tahunAjaranAktif->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Saat ini belum ada tahun ajaran yang sedang aktif!'
                ], 404);
            }

            $lastKRS = KRS::where('tahun_id', $tahunAjaranAktif['tahun_id'])
                ->where('mhs_id', $mahasiswa['mhs_id'])
                ->first();

            if($isDebug && $debugSection === '1') {
                return $this->debug_log([
                    'lastKRS' => $lastKRS
                ]);
            }
            

            if ($lastKRS) {
                if ($lastKRS['sts_krs'] == 'S') {
                    $krsMatkul = KRSMatkul::getKRSMatkulWithKelasKuliah($lastKRS['krs_id'])->toArray();
                    $kelasKuliah = array_map(function ($item) {
                        return $item['kelas_kuliah_join'];
                    }, $krsMatkul);

                    if($isDebug && $debugSection === '2') {
                        return $this->debug_log([
                            'lastKRS' => $lastKRS,
                            'krsMatkul' => $krsMatkul,
                            'kelasKuliah' => $kelasKuliah
                        ]);
                    }

                    if (count($kelasKuliah) > 0) {
                        foreach ($kelasKuliah as $index => $item) {

                            if(isset($item['kelas_kuliah_id'])) {

                                $jadwal = JadwalView::getJadwalKelasKuliah($item['kelas_kuliah_id'], $mahasiswa['mhs_id'], false);
    
                                $kontrakKuliah = KontrakKelasKuliah::getKontrakKelasKuliah($item);
    
                                // get riwayat presensi mahasiswa
                                $arrPertemuan = Pertemuan::where('kelas_kuliah_id', $item['kelas_kuliah_id'])
                                    ->select('pertemuan_id')
                                    ->get()
                                    ->pluck('pertemuan_id')
                                    ->toArray();
    
                                $riwayatPresensi = [];
    
                                if ($arrPertemuan) {
                                    $riwayatPresensi = Presensi::whereIn('pertemuan_id', $arrPertemuan)
                                        ->where('mhs_id', $mahasiswa['mhs_id'])
                                        ->select('masuk')
                                        ->get();
                                }
    
                                $formattedItem = [
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
                                    'riwayat_presensi' => $riwayatPresensi,
                                    'riwayat_presensi_maks' => 20, // sementara, untuk menentukan maksimal presensi atau pertemuan kelas,
                                    'kontrak_kuliah' => $kontrakKuliah->last(),
                                    'minimal_presensi' => [
                                        'persentase' => $item['tahun_ajaran']['minimal_presensi']
                                            ? $item['tahun_ajaran']['minimal_presensi']['persentase']
                                            : 0,
                                        'is_exist' => $item['tahun_ajaran']['minimal_presensi'] ? true : false
                                    ],
                                    'kelas_kuliah_id_exist' => true
                                ];
    
                                $kelasKuliah[$index] = self::setKelasKuliahAndJadwalProperties($formattedItem, $jadwal);
                            }else{

                                $matakuliah = MatKulView::where('mk_id', $krsMatkul[$index]['mk_id'])
                                    ->select('mk_id', 'kd_mk', 'nm_mk', 'semester', 'sks', 'sts_mk', 'smt')
                                    ->first();

                                $formattedItem = [
                                    'data_kelas' => [
                                        'kelas_kuliah_id' => null,
                                        'tahun_id' => $lastKRS['tahun_id'],
                                        'jur_id' => $matakuliah ? $matakuliah['jur_id'] : null,
                                        'mk_id' => $krsMatkul[$index]['mk_id'],
                                        'join_kelas_kuliah_id' => null,
                                        'kjoin_kelas' => null,
                                        'kelas_kuliah' => null,
                                        'jns_mhs' => $lastKRS['jns_mhs'],
                                        'sts_kelas' => null,
                                        'pengajar_id' => null,
                                        'join_jur' => null,
                                    ],
                                    'dosen' => null,
                                    'matakuliah' => $matakuliah,
                                    'riwayat_presensi' => [],
                                    'riwayat_presensi_maks' => 20, // sementara, untuk menentukan maksimal presensi atau pertemuan kelas,
                                    'kontrak_kuliah' => null,
                                    'minimal_presensi' => null,
                                    'kelas_kuliah_id_exist' => false
                                ];

                                $kelasKuliah[$index] = self::setKelasKuliahAndJadwalProperties($formattedItem, null);
                            }
                        }

                        // urutkan berdasarkan nama hari, Senin, Selasa, ... Minggu, Unknown
                        $orderedKelasKuliahByNamaHari = self::orderingKelasKuliahByNamaHari($kelasKuliah);

                        // terdapat query 'hari'
                        if ($filterHari) {
                            return self::filterKelasKuliahByHari($orderedKelasKuliahByNamaHari, $filterHari);
                        }

                        // ubah ke array
                        $transformedResponse = [];

                        foreach ($orderedKelasKuliahByNamaHari as $key => $item) {
                            $transformedResponse[] = [$key => $item];
                        }

                        // semua jadwal
                        return $this->successfulResponseJSON([
                            'kelas_kuliah' => $transformedResponse
                        ]);
                    }

                    return response()->json([
                        'status' => 'fail',
                        'message' => 'Kelas kuliah tidak ditemukan pada pengajuan KRS terakhir'
                    ], 404);
                }

                return response()->json([
                    'status' => 'fail',
                    'message' => 'Status KRS belum mengajukan atau masih dalam tahap pengajuan'
                ], 404);
            }

            return $this->failedResponseJSON('Kelas kuliah tidak ditemukan', 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getTrace()
            ], 500);
        }
    }

    private function filterKelasKuliahByHari($kelasKuliah, $hari) {
        $ucHari = ucfirst(strtolower($hari));

        return $this->successfulResponseJSON([
            'kelas_kuliah' => [
                [ $ucHari => $kelasKuliah[$ucHari] ?? [] ],
            ]
        ]);
    }

    private function setKelasKuliahAndJadwalProperties($objKelasKuliah, $objJadwal) {
        if ($objKelasKuliah['data_kelas']['join_jur']) {
            $objKelasKuliah['data_kelas']['join_jur'] = trim($objKelasKuliah['data_kelas']['join_jur']);
        }

        $objKelasKuliah['jadwal'] = $objJadwal !== null ? $objJadwal->exists() ? $objJadwal : null : null;
        
        if($objJadwal !== null) {
            if ($objJadwal->exists()) {
                // format ke waktu lokal
                $carbonDate = Carbon::parse($objJadwal['tanggal']);
                $carbonDate->setLocale('id');
    
                $objKelasKuliah['jadwal']['kd_ruang'] = trim($objJadwal['kd_ruang']);
                $objKelasKuliah['jadwal']['nm_hari'] = $carbonDate->dayName;
                $objKelasKuliah['jadwal']['tanggal_lokal'] = $carbonDate->isoFormat('D MMMM Y');
            }
        }

        $objKelasKuliah['dosen'] = self::trimNamaDanGelarDosen($objKelasKuliah['dosen']);

        /**
         * Untuk buka presensi, sementara dulu karena belum ada db
         */
        $objKelasKuliah['kelas_dibuka'] = self::setKelasDibuka($objKelasKuliah);

        return $objKelasKuliah;
    }

    private function orderingKelasKuliahByNamaHari($kelasKuliah) {
        $urutanHari = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu', 'Unknown'];
        $orderedKelasKuliahByNamaHari = [];

        // return $kelasKuliah[0]['jadwal'];

        $groupedKelasKuliahByHari = collect($kelasKuliah)->groupBy(function ($item) {
            return $item['jadwal'] ? $item['jadwal']['nm_hari'] : 'Unknown';
        })->toArray();

        foreach ($groupedKelasKuliahByHari as $index => $item) {
            $orderedKelasKuliahByNamaHari[array_search($index, $urutanHari)] = $item;
        }

        ksort($orderedKelasKuliahByNamaHari);

        // ganti nomor urutan dengan nama hari
        $finalOrderedKelasKuliah = [];
        foreach ($orderedKelasKuliahByNamaHari as $index => $item) {
            $finalOrderedKelasKuliah[$urutanHari[$index]] = $item;
        }

        return $finalOrderedKelasKuliah;
    }

    private function trimNamaDanGelarDosen($objDosen) {
        if ($objDosen) {
            $objDosen['nm_dosen'] = trim($objDosen['nm_dosen']);
            $objDosen['gelar'] = trim($objDosen['gelar']);

            return $objDosen;
        }

        return null;
    }

    private function setKelasDibuka($objKelasKuliah) {
        if ($objKelasKuliah['jadwal']) {
            $pertemuan = Pertemuan::getKelasDibuka($objKelasKuliah);

            if ($pertemuan->exists()) {
                return true;
            }
        }

        return false;
    }

    public function getBAPbyKelasKuliahId(Request $request, int $kelas_kuliah_id) {
        try {
            $kelasKuliahId = $kelas_kuliah_id;

            if(!$kelasKuliahId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Harap masukkan kelas kuliah id'
                ], 403);
            }

            $kelasKuliah = KelasKuliahJoinView::where('kelas_kuliah_id', $kelasKuliahId)
                ->with('dosen:dosen_id,nm_dosen,kd_dosen,gelar')
                ->with('matakuliah:mk_id,nm_mk,kd_mk,sks')
                ->first(['kelas_kuliah_id', 'tahun_id', 'mk_id', 'kjoin_kelas', 'join_kelas_kuliah_id', 'pengajar_id']);

            if(!$kelasKuliah) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kelas Kuliah tidak ditemukan'
                ], 404);
            }

            $berita_acara = BeritaAcara::where('kelas_kuliah_id', $kelasKuliahId)
                ->get(['berita_acara', 'jml_mhs', 'mhs_hdr', 'mhs_tdk_hdr', 'created_at', 'berita_acara_id']);

            return response()->json([
                'success' => true,
                'data' => $berita_acara
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
