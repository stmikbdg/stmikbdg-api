<?php

namespace App\Http\Controllers\Kuliah;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\KelasKuliah\BeritaAcara;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ? Models - tabels
use App\Models\TahunAjaranView;
use App\Models\KelasKuliah\KelasKuliahJoinView;
use App\Models\KelasKuliah\Pertemuan;
use App\Models\KelasKuliah\Presensi;
use App\Models\KRS\KRSMatkul;
use App\Models\Users\Mahasiswa;

class RekapPresensiController extends Controller
{
    public function getListDosen(Request $request) {
        try {
            $tahunId = $request->query('tahun_id');

            if ($tahunId) {
                $tahunAjaranExists = TahunAjaranView::where('tahun_id', $tahunId)->exists();

                if ($tahunAjaranExists) {
                    $dosenList = KelasKuliahJoinView::where('tahun_id', $tahunId)
                        ->whereNot('pengajar_id', null)
                        ->select('pengajar_id')
                        ->with('dosen:dosen_id,kd_dosen,nm_dosen')
                        ->distinct()
                        ->get();
                    $dosenMengajar = $dosenList->map(function ($item) use ($tahunId) {
                        return [
                            'tahun_id' => (int) $tahunId,
                            'dosen_id' => $item['pengajar_id'],
                            'nm_dosen' => trim($item->dosen->nm_dosen)
                        ];
                    })->values();

                    return $this->successfulResponseJSON([
                        'dosen_mengajar' => $dosenMengajar
                    ]);
                }
            }

            return $this->failedResponseJSON('Nilai query tahun_id tidak ditemukan', 404);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getListMatkul(Request $request) {
        try {
            $dosenId = $request->query('dosen_id');
            $tahunId = $request->query('tahun_id');

            if ($dosenId and $tahunId) {
                $tahunAndDosenExists = KelasKuliahJoinView::where('tahun_id', $tahunId)
                    ->where('pengajar_id', $dosenId)
                    ->exists();

                if ($tahunAndDosenExists) {
                    $matkulList = KelasKuliahJoinView::where('tahun_id', $tahunId)
                        ->where('pengajar_id', $dosenId)
                        ->select('kelas_kuliah_id', 'kjoin_kelas', 'join_kelas_kuliah_id', 'mk_id')
                        ->with('matakuliah:mk_id,nm_mk,kd_mk')
                        ->get();

                    return $this->successfulResponseJSON([
                        'matakuliah_diselenggarakan' => $matkulList
                    ]);
                }
            }

            return $this->failedResponseJSON('Nilai query tahun_id atau dosen_id tidak ditemukan', 404);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getRekapPresensi(Request $request) {
        try {
            $kelasKuliahId = $request->query('kelas_kuliah_id');

            if ($kelasKuliahId) {
                $kelasKuliah = KelasKuliahJoinView::where('kelas_kuliah_id', $kelasKuliahId)
                    ->with('dosen:dosen_id,nm_dosen,gelar,kd_dosen')
                    ->with('matakuliah:mk_id,nm_mk,kd_mk,sks')
                    ->first();

                if ($kelasKuliah) {
                    $allPertemuan = Pertemuan::where('kelas_kuliah_id', $kelasKuliahId)->get();
                    $pertemuanIdArr = $allPertemuan->pluck('pertemuan_id')->flatten();
                    $totalPertemuan = $allPertemuan->count();
                    $allPresensi = Presensi::whereIn('pertemuan_id', $pertemuanIdArr)
                        ->groupBy('mhs_id', 'nim', 'nm_mhs')
                        ->select(
                            'mhs_id',
                            'nim',
                            'nm_mhs',
                            DB::raw('COUNT(masuk) as total_kehadiran'),
                            DB::raw("(COUNT(masuk) / $totalPertemuan) * 100 as persentase_kehadiran")
                        )
                        ->get();

                    foreach ($allPresensi as $index => $item) {
                        $allPresensi[$index]['total_pertemuan'] = $totalPertemuan;
                        $lastKrsIdMhs = Mahasiswa::where('mhs_id', $item['mhs_id'])
                            ->first(['mhs_id', 'krs_id_last']);
                        $krsMatkulNilai = KRSMatkul::where('krs_id', $lastKrsIdMhs['krs_id_last'])
                            ->first(['krs_id', 'n_tugas', 'n_uts', 'n_uas', 'n_tambahan', 'n_akhir']);
                        $allPresensi[$index]['nilai_matkul'] = $krsMatkulNilai;
                    }

                    $tahunAjaran = TahunAjaranView::where('tahun_id', $kelasKuliah['tahun_id'])
                        ->first(['tahun_id', 'jur_id', 'jns_mhs', 'kd_kampus', 'uraian', 'tgl_kuliah']);
                    $dosen = $kelasKuliah->dosen;
                    $matkul = $kelasKuliah->matakuliah;
                    $totalMahasiswa = $kelasKuliah->krsMatkul()->count();

                    return $this->successfulResponseJSON([
                        'rekap_presensi' => [
                            'total_mahasiswa' => $totalMahasiswa,
                            'tahun_ajaran' => $tahunAjaran,
                            'dosen' => $dosen,
                            'matakuliah' => $matkul,
                            'kehadiran_mahasiswa' => $allPresensi
                        ]
                    ]);
                }
            }

            return $this->failedResponseJSON('Nilai query kelas_kuliah_id tidak ditemukan', 404);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getRekapPertemuan(Request $request) {
        try {
            $kelasKuliahId = $request->query('kelas_kuliah_id');
            $fromDate = $request->query('from');
            $toDate = $request->query('to');

            if ($kelasKuliahId and $fromDate and $toDate) {
                $kelasKuliah = KelasKuliahJoinView::where('kelas_kuliah_id', $kelasKuliahId)
                    ->with('dosen:dosen_id,nm_dosen,kd_dosen,gelar')
                    ->with('matakuliah:mk_id,nm_mk,kd_mk,sks')
                    ->first(['kelas_kuliah_id', 'tahun_id', 'mk_id', 'kjoin_kelas', 'join_kelas_kuliah_id', 'pengajar_id']);

                if ($kelasKuliah) {
                    // cek kemungkinan kelas join
                    $tempKelasKuliahIdArr = [];

                    if ($kelasKuliah['kjoin_kelas']) {
                        $kelasKuliahIdParent = $kelasKuliah['join_kelas_kuliah_id'];
                    } else {
                        $kelasKuliahIdParent = $kelasKuliah['kelas_kuliah_id'];
                    }

                    // get kelas-kelas yang dijoin dengan kelas parent
                    $kelasKuliahJoinIdArr = KelasKuliahJoinView::where('join_kelas_kuliah_id', $kelasKuliahIdParent)
                        ->get(['kelas_kuliah_id'])
                        ->pluck('kelas_kuliah_id')
                        ->toArray();
                    $tempKelasKuliahIdArr = array_merge($kelasKuliahJoinIdArr, [$kelasKuliahIdParent]);

                    // hitung total mahasiswa
                    $totalMahasiswa = KRSMatkul::whereIn('kelas_kuliah_id', $tempKelasKuliahIdArr)->count();

                    $tahunIdArr = KelasKuliahJoinView::whereIn('kelas_kuliah_id', $tempKelasKuliahIdArr)
                        ->get(['tahun_id'])
                        ->pluck('tahun_id')
                        ->toArray();

                    // get uraian tahun ajaran untuk setiap kelas
                    $tahunAjaranArr = TahunAjaranView::whereIn('tahun_id', $tahunIdArr)
                        ->get(['tahun_id', 'uraian', 'kd_kampus', 'jns_mhs']);

                    // get semua pertemuan
                    $pertemuan = Pertemuan::whereBetween('tanggal', [$fromDate, $toDate])
                        ->where('kelas_kuliah_id', $kelasKuliahId)
                        ->get(['tanggal']);

                    return $this->successfulResponseJSON([
                        'total_mahasiswa' => $totalMahasiswa,
                        'total_pertemuan' => $pertemuan->count(),
                        'tahun_ajaran' => $tahunAjaranArr,
                        'dosen' => $kelasKuliah->dosen,
                        'matakuliah' => $kelasKuliah->matakuliah,
                        'rekap_pertemuan' => $pertemuan
                    ]);
                }
            }

            return $this->failedResponseJSON('Nilai query kelas_kuliah_id, from, atau to tidak ditemukan', 404);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getRekapPertemuanV2(Request $request) {
        try {
            // $kelasKuliahId = $request->query('kelas_kuliah_id');
            $pengajarId = $request->query('pengajar_id');
            $tahunId = $request->query('tahun_id');
            $fromDate = $request->query('from');
            $toDate = $request->query('to');

            if (!$pengajarId) {
                return $this->failedResponseJSON('Nilai query pengajar_id tidak ditemukan', 404);
            }

            if (!$tahunId) {
                return $this->failedResponseJSON('Nilai query tahun_id tidak ditemukan', 404);
            }

            if (!$fromDate) {
                return $this->failedResponseJSON('Nilai query from tidak ditemukan', 404);
            }

            if (!$toDate) {
                return $this->failedResponseJSON('Nilai query to tidak ditemukan', 404);
            }

            $kelasKuliahArr = KelasKuliahJoinView::where('pengajar_id', $pengajarId)
                ->where('tahun_id', $tahunId)
                ->with('dosen:dosen_id,nm_dosen,kd_dosen,gelar')
                ->with('matakuliah:mk_id,nm_mk,kd_mk,sks')
                ->select(['kelas_kuliah_id', 'tahun_id', 'mk_id', 'kjoin_kelas', 'join_kelas_kuliah_id', 'pengajar_id', 'jns_mhs', 'kelas_kuliah'])
                ->get();

            if($kelasKuliahArr->count() < 0) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            $pertemuan = Pertemuan::whereBetween('tanggal', [$fromDate, $toDate])
                ->whereIn('kelas_kuliah_id', $kelasKuliahArr->pluck('kelas_kuliah_id'))
                ->orderBy('create_time', 'ASC')
                ->get();

            $data = $pertemuan->map(function ($item) use ($kelasKuliahArr) {

                $item['kelas_kuliah'] = $kelasKuliahArr->where('kelas_kuliah_id', $item['kelas_kuliah_id'])->values()->first();

                return $item;
            });

            return response()->json([
                'success' => true,
                'data' => $data
            ]);


            foreach ($kelasKuliahArr as $kelasKuliah) {
                $tempKelasKuliahIdArr = [];

                if ($kelasKuliah['kjoin_kelas']) {
                    $kelasKuliahIdParent = $kelasKuliah['join_kelas_kuliah_id'];
                } else {
                    $kelasKuliahIdParent = $kelasKuliah['kelas_kuliah_id'];
                }

                // get kelas-kelas yang dijoin dengan kelas parent
                $kelasKuliahJoinIdArr = KelasKuliahJoinView::where('join_kelas_kuliah_id', $kelasKuliahIdParent)
                    ->get(['kelas_kuliah_id'])
                    ->pluck('kelas_kuliah_id')
                    ->toArray();
                $tempKelasKuliahIdArr = array_merge($kelasKuliahJoinIdArr, [$kelasKuliahIdParent]);

                // hitung total mahasiswa
                $totalMahasiswa = KRSMatkul::whereIn('kelas_kuliah_id', $tempKelasKuliahIdArr)->count();

                $tahunIdArr = KelasKuliahJoinView::whereIn('kelas_kuliah_id', $tempKelasKuliahIdArr)
                    ->get(['tahun_id'])
                    ->pluck('tahun_id')
                    ->toArray();

                // get uraian tahun ajaran untuk setiap kelas
                $tahunAjaranArr = TahunAjaranView::whereIn('tahun_id', $tahunIdArr)
                    ->get(['tahun_id', 'uraian', 'kd_kampus', 'jns_mhs']);

                // get semua pertemuan
                $pertemuan = Pertemuan::whereBetween('tanggal', [$fromDate, $toDate])
                    ->where('kelas_kuliah_id', $kelasKuliah['kelas_kuliah_id'])
                    ->get();

                // return $this->successfulResponseJSON([
                //     'total_mahasiswa' => $totalMahasiswa,
                //     'total_pertemuan' => $pertemuan->count(),
                //     'tahun_ajaran' => $tahunAjaranArr,
                //     'dosen' => $kelasKuliah->dosen,
                //     'matakuliah' => $kelasKuliah->matakuliah,
                //     'rekap_pertemuan' => $pertemuan
                // ]);

                // $data[] = $pertemuan;
                // return $this->successfulResponseJSON();
                // $data[] = [
                //     'total_mahasiswa' => $totalMahasiswa,
                //     'total_pertemuan' => $pertemuan->count(),
                //     // 'tahun_ajaran' => $tahunAjaranArr,
                //     'dosen' => $kelasKuliah->dosen,
                //     'matakuliah' => $kelasKuliah->matakuliah,
                //     'rekap_pertemuan' => $pertemuan
                // ];
                $data[] = [
                    // 'total_mahasiswa' => $totalMahasiswa,
                    // 'total_pertemuan' => $pertemuan->count(),
                    // // 'tahun_ajaran' => $tahunAjaranArr,
                    // 'dosen' => $kelasKuliah->dosen,
                    // 'matakuliah' => $kelasKuliah->matakuliah,
                    'pertemuan' => $pertemuan,
                    'kelas_kuliah' => $kelasKuliah
                ];
            }

            return response()->json([
                'sucess' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getRekapBeritaAcara(Request $request) {
        try {
            $kelasKuliahId = $request->query('kelas_kuliah_id');
            $fromDate = $request->query('from');
            $toDate = $request->query('to');

            if ($kelasKuliahId and $fromDate and $toDate) {
                $kelasKuliah = KelasKuliahJoinView::where('kelas_kuliah_id', $kelasKuliahId)
                    ->with('dosen:dosen_id,nm_dosen,kd_dosen,gelar')
                    ->with('matakuliah:mk_id,nm_mk,kd_mk,sks')
                    ->first(['kelas_kuliah_id', 'tahun_id', 'mk_id', 'kjoin_kelas', 'join_kelas_kuliah_id', 'pengajar_id']);

                if ($kelasKuliah) {

                    $berita_acara = BeritaAcara::whereBetween('created_at', [$fromDate, $toDate])
                        ->where('kelas_kuliah_id', $kelasKuliahId)
                        ->get(['berita_acara', 'jml_mhs', 'mhs_hdr', 'mhs_tdk_hdr', 'created_at', 'berita_acara_id']);

                    return $this->successfulResponseJSON([
                        'berita_acara' => $berita_acara
                    ]);
                }
            }

            return $this->failedResponseJSON('Nilai query kelas_kuliah_id, from, atau to tidak ditemukan', 404);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getFilterBAPDosenByMatkul(Request $request, int $tahunId) {
        try {
            $dosen = $this->getUserAuth();

            // dd($dosen);
            $dosenId = $dosen['dosen_id'];
            // $tahunId = $request->query('tahun_id');

            if ($dosenId and $tahunId) {
                $tahunAndDosenExists = KelasKuliahJoinView::where('tahun_id', $tahunId)
                    ->where('pengajar_id', $dosenId)
                    ->exists();

                if ($tahunAndDosenExists) {
                    $matkulList = KelasKuliahJoinView::where('tahun_id', $tahunId)
                        ->where('pengajar_id', $dosenId)
                        ->select('kelas_kuliah_id', 'kjoin_kelas', 'join_kelas_kuliah_id', 'mk_id')
                        ->with('matakuliah:mk_id,nm_mk,kd_mk')
                        ->get();

                    return $this->successfulResponseJSON([
                        'matakuliah_diselenggarakan' => $matkulList
                    ]);
                }
            }
        } catch (\Exception $error) {

        }
    }

    public function getBAPDosenByKelasKuliahId(Request $request, int $kelas_kuliah_id) {
        $kelasKuliahId = $kelas_kuliah_id;
        $fromDate = $request->query('from');
        $toDate = $request->query('to');

        $data = [];

        if ($fromDate and $toDate) {
            $kelasKuliah = KelasKuliahJoinView::where('kelas_kuliah_id', $kelasKuliahId)
                ->with('dosen:dosen_id,nm_dosen,kd_dosen,gelar')
                ->with('matakuliah:mk_id,nm_mk,kd_mk,sks')
                ->first(['kelas_kuliah_id', 'tahun_id', 'mk_id', 'kjoin_kelas', 'join_kelas_kuliah_id', 'pengajar_id']);

            if ($kelasKuliah) {

                $berita_acara = BeritaAcara::whereBetween('created_at', [$fromDate, $toDate])
                    ->where('kelas_kuliah_id', $kelasKuliahId)
                    ->get(['berita_acara', 'jml_mhs', 'mhs_hdr', 'mhs_tdk_hdr', 'created_at', 'berita_acara_id']);

                $data = $berita_acara;
            }
        }else{
            $kelasKuliah = KelasKuliahJoinView::where('kelas_kuliah_id', $kelasKuliahId)
                ->with('dosen:dosen_id,nm_dosen,kd_dosen,gelar')
                ->with('matakuliah:mk_id,nm_mk,kd_mk,sks')
                ->first(['kelas_kuliah_id', 'tahun_id', 'mk_id', 'kjoin_kelas', 'join_kelas_kuliah_id', 'pengajar_id']);

            if ($kelasKuliah) {

                $berita_acara = BeritaAcara::where('kelas_kuliah_id', $kelasKuliahId)
                    ->get(['berita_acara', 'jml_mhs', 'mhs_hdr', 'mhs_tdk_hdr', 'created_at', 'berita_acara_id']);

                $data = $berita_acara;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }
}
