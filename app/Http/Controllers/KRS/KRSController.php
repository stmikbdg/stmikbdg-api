<?php

namespace App\Http\Controllers\KRS;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\TahunAjaranController;

// ? Exceptions
use App\Exceptions\ErrorHandler;

// ? Models - table
use App\Models\KRS\KRS;
use App\Models\KRS\KRSMatkul;
use App\Models\Users\Mahasiswa;
use App\Models\Keuangan\StatusKeuangan;

// ? Models - view
use App\Models\TahunAjaranView;
use App\Models\KRS\MatkulDiselenggarakanView;
use App\Models\Users\Dosen;

class KRSController extends Controller
{
    private $currentSemester;
    private $user;

    public function __construct() {
        if (auth()->user()) {
            if (!auth()->user()->is_dosen) {
                $tahunAjaranController = new TahunAjaranController();

                $response = $tahunAjaranController
                    ->getSemesterMahasiswaSekarang()
                    ->getData('data');

                // dd($response);

                $this->currentSemester = $response['data']['semester'];
            }
            $this->user = $this->getUserAuth();
        }
    }

    public function checkKRS() {
        $tahunAjaran = TahunAjaranView::getTahunAjaran($this->user);
        if(!$tahunAjaran->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Saat ini belum ada tahun ajaran yang sedang aktif!'
            ], 404);
        }

        $krs = KRS::checkCurrentKRS(
            $tahunAjaran['tahun_id'], $this->user
        );

        // return response()->json([
        //     'data' => $krs
        // ]);

        if ($krs) {
            $dataKRS = self::getStatusKRS($krs, $tahunAjaran['du_open']);
            $dataKRS['krs']['sts_krs'] = $krs['sts_krs'];
            $dataKRS['krs']['nmr_krs'] = $krs['nmr_krs'];
            $dataKRS['krs']['semester'] = $krs['semester'];
            $dataKRS['krs']['krs_id'] = $krs['krs_id'];

            if (!is_null($krs['ditolak_tanggal']) and ($krs['sts_krs'] === 'D')) {
                $dataKRS['krs']['ditolak_tanggal'] = $krs['ditolak_tanggal'];
                $dataKRS['krs']['ditolak_alasan'] = $krs['ditolak_alasan'];
            }
        } else {
            $dataKRS = self::getStatusKRS(null, $tahunAjaran['du_open']);

            $dataKRS['krs']['sts_krs'] = null;
            $dataKRS['krs']['nmr_krs'] = null;
            $dataKRS['krs']['semester'] = $this->currentSemester;
            $dataKRS['krs']['krs_id'] = null;
        }

        $desiredOrder = ['open', 'krs_id', 'sts_krs', 'keterangan_status', 'nmr_krs', 'semester'];
        $orderedData = array_replace(array_flip($desiredOrder), $dataKRS['krs']);
        $dataKRS['krs'] = $orderedData;

        // cek status keuangan untuk tiket krs
        $statusKeuangan = StatusKeuangan::getStatus($tahunAjaran['tahun_id'], $this->user['mhs_id']);
        $dataKRS['krs']['sts_tiket'] = $statusKeuangan->exists() ? $statusKeuangan['tiket_krs'] : null;

        if (is_null($dataKRS['krs']['sts_tiket']) or (!$dataKRS['krs']['sts_tiket'])) {
            $dataKRS['krs']['message'] = 'Tidak bisa melakukan Pengajuan. Mohon hubungi bagian Administrasi Keuangan';
        }

        return $this->successfulResponseJSON([
            'krs' => $dataKRS['krs'],
            'tahun_ajaran' => [
                'tahun_id' => $tahunAjaran['tahun_id'],
                'tahun' => $tahunAjaran['tahun'],
                'du_open' => $tahunAjaran['du_open'],
                'du_sampai' => $tahunAjaran['du_sampai'],
            ],
        ], $dataKRS['message']);
    }

    public function addKRSMahasiswa(Request $request) {
        try {
            /**
             * * kode sts_krs:
             * P = Pengajuan
             * D = Draft
             * S = Setujui
             *
             * Tahapan proses ke db:
             * add ke tabel krs terlebih dahulu, kemudian ambil id-nya
             * add setiap mata_kuliah yang dipilih ke tabel krs_mk dengan krs_id dari tahap sebelumnya
             * Jika krs bukanlah draft, update krs_id_last di tabel mahasiswa
             */
            $checkKRS = self::checkKRS();
            $statusKRS = $checkKRS->getData('data')['data']['krs']['sts_krs'];
            $krsId = $checkKRS->getData('data')['data']['krs']['krs_id'];
            $krsOpen = $checkKRS->getData('data')['data']['krs']['open'];

            // validasi request
            $request->validate([
                'tahun_id' => 'required',
                'pengajuan_catatan' => 'nullable|string',
                'mata_kuliah' => 'required|array'
            ]);
            $tahunAjaran = TahunAjaranView::where('tahun_id', $request->tahun_id)->first();

            /**
             * cek status keuangan
             */
            $statusKeuangan = StatusKeuangan::getStatus($tahunAjaran['tahun_id'], $this->user['mhs_id']);
            $getValStatusKeuangan = $statusKeuangan->exists() ? $statusKeuangan['tiket_krs'] : null;

            if (is_null($getValStatusKeuangan) or (!$getValStatusKeuangan)) {
                return $this->failedResponseJSON(
                    'Tidak bisa melakukan Pengajuan. Mohon hubungi bagian Administrasi Keuangan', 402
                );
            }

            // jika sts_krs 'D' dan terdapat krs_id
            if ($statusKRS === 'D') {
                // dari draft (D) ubah ke pengajuan (P)
                KRS::where('krs_id', $krsId)->update(['sts_krs' => 'P']);
                $setMatkul = self::setMatkulKRS($tahunAjaran, $request->mata_kuliah);

                // jika setMatkul null, berarti mk yang dipilih tidak dibuka di tahun ajaran tersebut
                if (is_null($setMatkul)) {
                    return response()->json([
                        'status' => 'fail',
                        'message' => 'Matakuliah yang dipilih tidak tersedia'
                    ], 400);
                }

                $completedMatkulData = self::setKRSIdToMatkul($krsId, $setMatkul);

                KRSMatkul::where('krs_id', $krsId)->delete();
                KRSMatkul::insert($completedMatkulData);
            } else if (($statusKRS === 'P') or ($statusKRS === 'S') or (!$krsOpen)) {
                // sudah diajukan atau sudah disetujui
                return $checkKRS;
            } else {
                $krsData = self::setKRSData($tahunAjaran);
                $krsData['pengajuan_catatan'] = $request->pengajuan_catatan;

                /**
                 * Atur matkul sesuai matkul diselenggarakan filter by tahun ajaran
                 * Tambahkan krsId yang berhasil diinsert ke setiap matkul
                 */
                $setMatkul = self::setMatkulKRS($tahunAjaran, $request->mata_kuliah);

                // jika setMatkul null, berarti mk yang dipilih tidak dibuka di tahun ajaran tersebut
                if (is_null($setMatkul)) {
                    return response()->json([
                        'status' => 'fail',
                        'message' => 'Matakuliah yang dipilih tidak tersedia'
                    ], 402);
                }

                $insertedKRSId = KRS::create($krsData)->krs_id;
                $completedMatkulData = self::setKRSIdToMatkul($insertedKRSId, $setMatkul);

                KRSMatkul::insert($completedMatkulData);
                Mahasiswa::where('mhs_id', $this->user['mhs_id'])
                    ->update([
                        'krs_id_last' => $insertedKRSId
                    ]);
            }

            return $this->successfulResponseJSON([
                'krs_id' => is_null($krsId) ? $insertedKRSId : $krsId,
            ], 'Berhasil mengirim pengajuan KRS', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function addDraftKRSMahasiswa(Request $request) {
        /**
         * * kode sts_krs:
         * P = Pengajuan
         * D = Draft
         * S = Setujui
         *
         * Tahapan proses ke db:
         * add ke tabel krs terlebih dahulu, kemudian ambil id-nya
         * add setiap mata_kuliah yang dipilih ke tabel krs_mk dengan krs_id dari tahap sebelumnya
         * Jika draft pertama kali dibuat, update krs_id_last di tabel mahasiswa
         */
        try {
            $checkKRS = self::checkKRS();
            $krsId = $checkKRS->getData('data')['data']['krs']['krs_id'];
            $statusKRS = $checkKRS->getData('data')['data']['krs']['sts_krs'];
            $krsOpen = $checkKRS->getData('data')['data']['krs']['open'];

            // validasi request
            $request->validate([
                'tahun_id' => 'required',
                'pengajuan_catatan' => 'nullable|string',
                'mata_kuliah' => 'required|array'
            ]);
            $tahunAjaran = TahunAjaranView::where('tahun_id', $request->tahun_id)->first();

            /**
             * cek status keuangan
             */
            $statusKeuangan = StatusKeuangan::getStatus($tahunAjaran['tahun_id'], $this->user['mhs_id']);
            $getValStatusKeuangan = $statusKeuangan->exists() ? $statusKeuangan['tiket_krs'] : null;

            if (is_null($getValStatusKeuangan) or (!$getValStatusKeuangan)) {
                return $this->failedResponseJSON(
                    'Tidak bisa melakukan Pengajuan. Mohon hubungi bagian Administrasi Keuangan', 402
                );
            }

            if (is_null($krsId)) {
                if ($krsOpen) {
                    $setMatkul = self::setMatkulKRS($tahunAjaran, $request->mata_kuliah);
                    $krsData = self::setKRSData($tahunAjaran, 'D'); // simpan sebagai draft 'D'
                    $krsData['pengajuan_catatan'] = $request->pengajuan_catatan;
                    $insertedKRSId = KRS::create($krsData)->krs_id;

                    // jika setMatkul null, berarti mk yang dipilih tidak dibuka di tahun ajaran tersebut
                    if (is_null($setMatkul)) {
                        return response()->json([
                            'status' => 'fail',
                            'message' => 'Matakuliah yang dipilih tidak tersedia'
                        ], 400);
                    }

                    $completedMatkulData = self::setKRSIdToMatkul($insertedKRSId, $setMatkul);

                    KRSMatkul::insert($completedMatkulData);
                    Mahasiswa::where('mhs_id', $this->user['mhs_id'])
                                ->update([
                                    'krs_id_last' => $insertedKRSId
                                ]);
                }
            } else if (($statusKRS === 'P') or ($statusKRS === 'S') or (!$krsOpen)) {
                // sudah diajukan atau sudah disetujui
                return $checkKRS;
            } else if ($statusKRS === 'D') {
                $setMatkul = self::setMatkulKRS($tahunAjaran, $request->mata_kuliah);

                // jika setMatkul null, berarti mk yang dipilih tidak dibuka di tahun ajaran tersebut
                if (is_null($setMatkul)) {
                    return response()->json([
                        'status' => 'fail',
                        'message' => 'Matakuliah yang dipilih tidak tersedia'
                    ], 400);
                }

                $completedMatkulData = self::setKRSIdToMatkul($krsId, $setMatkul);
                KRSMatkul::where('krs_id', $krsId)->delete();
                KRSMatkul::insert($completedMatkulData);
            }

            return $this->successfulResponseJSON([
                'krs_id' => is_null($krsId) ? $insertedKRSId : $krsId,
            ], 'Berhasil menyimpan KRS sebagai draft', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getDraftKRSMatkul() {
        try {
            $tahunAjaranAktif = TahunAjaranView::getTahunAjaran($this->user);

            /**
             * cek status keuangan
             */
            $statusKeuangan = StatusKeuangan::getStatus($tahunAjaranAktif['tahun_id'], $this->user['mhs_id']);
            $getValStatusKeuangan = $statusKeuangan->exists() ? $statusKeuangan['tiket_krs'] : null;

            if (is_null($getValStatusKeuangan) or (!$getValStatusKeuangan)) {
                return $this->failedResponseJSON(
                    'Tidak bisa melakukan Pengajuan. Mohon hubungi bagian Administrasi Keuangan', 402
                );
            }

            if ($tahunAjaranAktif['du_open']) {
                $lastKRS = KRS::where('tahun_id', $tahunAjaranAktif['tahun_id'])
                    ->where('mhs_id', $this->user['mhs_id'])
                    ->where('sts_krs', 'D')
                    ->first();

                if ($lastKRS) {
                    $krsMatkul = KRSMatkul::where('krs_id', $lastKRS['krs_id'])
                        ->select('krs_mk_id', 'krs_id', 'mk_id', 'sts_mk_krs')
                        ->get();

                    foreach ($krsMatkul as $index => $matkul) {
                        $detailMatkul = $matkul->matakuliah()
                            ->select('kd_mk', 'nm_mk', 'semester', 'sts_mk', 'kd_kur')
                            ->first();
                        $krsMatkul[$index]['detail_matkul'] = $detailMatkul;
                    }

                    $lastKRS['krs_matkul'] = $krsMatkul;

                    return $this->successfulResponseJSON([
                        'krs' => $lastKRS,
                    ]);
                }

                return response()->json([
                    'status' => 'fail',
                    'message' => 'Anda tidak memiliki draft KRS di tahun ajaran sekarang'
                ], 400);
            }

            return response()->json([
                'status' => 'fail',
                'message' => 'Waktu pengisian KRS telah ditutup'
            ], 400);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function setKRSData($tahunAjaran, $statusKRS = 'P') {
        $smt = $tahunAjaran['smt'];
        $kdJur = $this->user->jurusan()->first()['kd_jur'];
        $jnsMhs = $this->user['jns_mhs'];
        $nmrKRS = self::generateNmrKRSMahasiswa($kdJur, $jnsMhs, $smt, $tahunAjaran['tahun']);

        return [
            'tahun_id' => $tahunAjaran['tahun_id'],
            'nmr_krs' => $nmrKRS,
            'tanggal' => Carbon::now()->format('Y-m-d'),
            'mhs_id' => $this->user->mhs_id,
            'kd_kampus' => $tahunAjaran['kd_kampus'],
            'semester' => $this->currentSemester,
            'sts_krs' => $statusKRS,
            'dosen_id' => null,
            'ditolak_tanggal' => null,
            'ditolak_alasan' => null,
            'ditolak_stlh_sah' => null,
            'kd_chanel' => 'A', // ! lupa lagi maksudnya apa
        ];
    }

    private function setMatkulKRS($tahunAjaran, $matkul) {
        $filter = [
            'tahun_id' => $tahunAjaran['tahun_id'],
            'kd_kampus' => $tahunAjaran['kd_kampus'],
            'jns_mhs' => $tahunAjaran['jns_mhs'],
            'jur_id' => $tahunAjaran['jur_id']
        ];

        foreach ($matkul as $index => $item) {
            $filter['mk_id'] = $item['mk_id'];
            $matkulData = MatkulDiselenggarakanView::getOneMatkul($filter);

            // jika maktul tidak ditemukan, maka kembalikan null
            if (count($matkulData) < 1) return null;

            $setMatkul[$index]['mk_id'] = $matkulData[0]['mk_id'];
            $setMatkul[$index]['sts_mk_krs'] = $matkulData[0]['sts_mk'];
            $setMatkul[$index]['tgl_perubahan'] = Carbon::now()->format('Y-m-d');
            $setMatkul[$index]['k_disetujui'] = false; // ! belum tau nilai defaulf dan maksudnya
            /**
             * Sisanya adalah nullable - dilihat dari tabel krs_mk
             */
        }

        return $setMatkul;
    }

    private function setKRSIdToMatkul($krsId, $matkul) {
        foreach ($matkul as $index => $item) {
            $setMatkul[$index] = $item;
            $setMatkul[$index]['krs_id'] = $krsId;
        }

        return $setMatkul;
    }

    private function generateNmrKRSMahasiswa($kdJur, $jnsMhs, $smt, $tahunAjaran) {
        /**
         * * Set nmr_krs
         * nomor urut / kdJur / jenis kelas / tahun
         */
        $substrTahun = substr($tahunAjaran, -2);
        $tempNmrKRS = "/$kdJur" . "/K$jnsMhs" . "$smt/" . $substrTahun;
        $lastNmrKRS = KRS::getLastNomorKRS($tempNmrKRS);

        // jika lastNmrKRS null, maka nmr_urutnya adalah 001
        if (!$lastNmrKRS) {
            $nmrKRS = '001' . $tempNmrKRS;
        } else {
            // mencegah nmr_urut yang sama saat submit di waktu bersamaan
            $lockNmrUrut = Cache::lock('nmr_urut', 60); // tahan nmrUrut semenit

            try {
                if ($lockNmrUrut->get()) {
                    $nmrUrutKRS = (int) explode('/', $lastNmrKRS)[0] + 1; // indeks 0 adalah nomor urut
                    $formattedNomorUmur = str_pad($nmrUrutKRS, 3, '0', STR_PAD_LEFT);
                    $nmrKRS = $formattedNomorUmur . $tempNmrKRS;
                } else {
                    $nextNmrUrutKRS = Cache::get('next_nmr_urut', 1) + 1;
                    $formattedNomorUmur = str_pad($nextNmrUrutKRS, 3, '0', STR_PAD_LEFT);
                    $nmrKRS = $formattedNomorUmur . $tempNmrKRS;

                    Cache::put('next_nmr_urut', $nextNmrUrutKRS, 60);
                }
            } catch (\Exception $e) {
                return ErrorHandler::handle($e);
            } finally {
                $lockNmrUrut->release();
            }
        }

        return $nmrKRS;
    }

    private function getStatusKRS($krs, $duOpen) {
        if (isset($krs['sts_krs'])) {
            if ($krs['sts_krs'] === 'S') {
                return [
                    'message' => 'Hanya Dosen Wali yang dapat mengembalikan status KRS yang sudah disetujui',
                    'krs' => [
                        'open' => false,
                        'keterangan_status' => 'Disetujui',
                    ],
                ];
            } else if ($krs['sts_krs'] === 'D') {
                return [
                    'message' => 'KRS tersimpan sebagai draft dan dapat bisa diubah sampai batas waktu',
                    'krs' => [
                        'open' => $duOpen,
                        'keterangan_status' => 'Draft',
                    ],
                ];
            } else if ($krs['sts_krs'] === 'P') {
                return [
                    'message' => 'KRS tidak dapat diubah karena telah diajukan dan sedang tahap review',
                    'krs' => [
                        'open' => false,
                        'keterangan_status' => 'Pengajuan',
                    ],
                ];
            }
        } else {
                return [
                    'message' => $duOpen ? 'Pengisian KRS dibuka' : 'Pengisian KRS ditutup',
                    'krs' => [
                        'open' => $duOpen,
                        'keterangan_status' => $duOpen ? 'Dibuka' : 'Ditutup',
                    ]
                ];
            }
    }

    public function getRiwayat(Request $request) {
        try {

            $krs_id = $request->query('krs_id');

            $user = $this->getUserAuth();

            if($krs_id) {
                $krs = KRS::with('krsMatkul.mataKuliah', 'tahun_ajaran')
                    ->where('mhs_id', $user['mhs_id'])
                    ->where('krs_id', $krs_id)
                    ->first();
            }else{
                $krs = KRS::with('krsMatkul.mataKuliah', 'tahun_ajaran')
                    ->where('mhs_id', $user['mhs_id'])->get();
            }

            return response()->json([
                'success' => true,
                'data' => $krs
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
