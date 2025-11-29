<?php

namespace App\Http\Controllers\Kuliah;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

// ? Models - view
use App\Models\KelasKuliah\KelasKuliahJoinView;
use App\Models\KelasKuliah\JadwalView;
use App\Models\KRS\MatKulView;

// ? Models - table
use App\Models\KelasKuliah\Pertemuan;
use App\Models\KelasKuliah\Presensi;
use App\Models\KelasKuliah\BeritaAcara;

class PertemuanController extends Controller {
    public function bukaKelasKuliah(Request $request, $kelasKuliahId) {
        try {
            $dosen = $this->getUserAuth();
            $kelasKuliah = KelasKuliahJoinView::getJoinJurusanData($kelasKuliahId);

            /**
             * Kelas kuliah tidak ditemukan
             */
            if (!$kelasKuliah->exists()) {
                return response()->json([
                    'status' => 'fail',
                    'message' => 'Kelas kuliah id tidak ditemukan',
                ], 400);
            }

            /**
             * Dosen yang membuka kelas haruslah pengajar di kelas tersebut
             */
            if ($kelasKuliah['pengajar_id'] !== $dosen['dosen_id']) {
                return response()->json([
                    'status' => 'fail',
                    'message' => 'Maaf, kelas kuliah hanya dapat dibuka oleh pengajarnya'
                ], 403);
            }

            $jadwal = JadwalView::getTanggalDanJenisPertemuan($kelasKuliahId);
            $parsedCarbonDate = Carbon::parse($jadwal['tanggal']);
            $currentDate = Carbon::now();

            /**
             * Tanggal kelas akan dibuka tidak sama
             * dengan jadwal yang telah ditentukan
             */
            if (!$currentDate->isSameDay($parsedCarbonDate)) {
                return response()->json([
                    'status' => 'fail',
                    'message' => 'Tanggal saat ini tidak sesuai dengan jadwal kelas kuliah'
                ], 400);
            }

            /**
             * Jika kelas_kuliah_id dan tanggal yang sama
             * sudah ada pada tabel pertemuan, artinya kelas pernah dibuka
             *
             * bila dosen ingin membuka kembali kelasnya
             * karena alasan seperti terdapat mahasiswa yang hadir di kelas
             * tapi lupa mengisi kehadiran. Maka cukup update nilai kelas_dibuka saja menjadi true
             */
            $kelasPernahDibuka = Pertemuan::cekKelasPernahDibukaInSameDay(
                $kelasKuliah['kelas_kuliah_id'], $kelasKuliah['pengajar_id'], $currentDate->format('Y-m-d')
            );

            // cek kelas join
            $kelasKuliahIdArr = [];

            /**
             * Jika kelas yang dibuka dan dijoin ke kelas lain
             */
            if ($kelasKuliah['kjoin_kelas']) {
                $kelasKuliahId = $kelasKuliah['join_kelas_kuliah_id'];
            }

            /**
             * Kelas-kelas yang dijoin
             */
            $kelasKuliahIdArr = KelasKuliahJoinView::where('join_kelas_kuliah_id', $kelasKuliahId)
                ->pluck('kelas_kuliah_id')->filter()->toArray();

            array_push($kelasKuliahIdArr, $kelasKuliahId);

            if ($kelasPernahDibuka->exists()) {
                /**
                 * Jika terdapat query param unique_pin = true, maka
                 * Generate unique random pin per mahasiswa
                 */
                if ($request->query('unique_pin')) {
                    $validatedValueUniquePIN = filter_var($request->query('unique_pin'), FILTER_VALIDATE_BOOLEAN);

                    /**
                     * Jika kelas sudah ditutup dan terdapat query param unique_pin,
                     * maka kelas harus dibuka terlebih dahulu dengan single pin
                     */
                    if (!$kelasPernahDibuka['kelas_dibuka']) {
                        return response()->json([
                            'status' => 'fail',
                            'message' => 'Buka kelas terlebih dahulu tanpa menggunakan query parameter unique_pin'
                        ], 400);
                    }

                    if ($validatedValueUniquePIN) {
                        $randomPIN = self::generateUniqueRandomPIN($kelasKuliahIdArr);
                    } else {
                        return response()->json([
                            'status' => 'fail',
                            'message' => 'Nilai unique_pin tidak ditemukan'
                        ], 404);
                    }
                } else {
                    /**
                     * Jika kelas ditutup, dan akan dibuka kembali di hari yang sama
                     */
                    Pertemuan::updateKelasDibuka($kelasKuliahIdArr, $kelasKuliah['pengajar_id'], true); // kelas_dibuka = true
                }
            } else {
                /**
                 * Request terdapat query param unique_pin,
                 * maka tolak permintaan kelas dibuka
                 */
                if ($request->query('unique_pin')) {
                    return response()->json([
                        'status' => 'fail',
                        'message' => 'Buka kelas terlebih dahulu tanpa menggunakan query parameter unique_pin'
                    ], 400);
                }

                /**
                 * Tanggal kelas pertama kali dibuka sesuai tanggal di jadwal
                 */
                $pertemuan = [
                    'kelas_kuliah_id' => $kelasKuliahId,
                    'jns_pert' => $jadwal['jns_pert'],
                    'tanggal' => $jadwal['tanggal'],
                    'jam' => $jadwal['jam'],
                    'kelas_dibuka' => true,
                    'create_time' => Carbon::now(),
                    'dosen_id' => $kelasKuliah['pengajar_id'],
                ];

                // jika ada kelas dijoin
                if (count($kelasKuliahIdArr) > 0) {
                    foreach ($kelasKuliahIdArr as $item) {
                        $pertemuan['kelas_kuliah_id'] = $item;
                        self::createDaftarKehadiran($pertemuan, $item);
                    }
                } else {
                    self::createDaftarKehadiran($pertemuan, $kelasKuliahId);
                }
            }

            /**
             * Saat kelas dibuka dan single PIN
             * maka generate pin single atau tetap
             */
            if (!$request->query('unique_pin')) {
                $randomPIN = self::generateSingleRandomPIN($kelasKuliahIdArr);
            }

            return $this->successfulResponseJSON([
                'pertemuan' => [
                    'kelas_kuliah_id_dibuka' => implode('-', $kelasKuliahIdArr),
                    'tanggal' => $jadwal['tanggal'],
                    'presensi' => $randomPIN,
                ],
            ]);

        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function tutupKelasKuliah(Request $request, $kelasKuliahId) {
        try {
            $request->validate([
                'berita_acara' => 'required|string'
            ]);

            $dosen = $this->getUserAuth();

            DB::beginTransaction();
            $kelasKuliah = KelasKuliahJoinView::getJoinJurusanData($kelasKuliahId);

            /**
             * Kelas kuliah tidak ditemukan
             */
            if (!$kelasKuliah->exists()) {
                return response()->json([
                    'status' => 'fail',
                    'message' => 'Kelas kuliah id tidak ditemukan',
                ], 400);
            }

            /**
             * Dosen yang menutup kelas haruslah pengajar di kelas tersebut
             */
            if ($kelasKuliah['pengajar_id'] !== $dosen['dosen_id']) {
                return response()->json([
                    'status' => 'fail',
                    'message' => 'Maaf, kelas kuliah hanya dapat ditutup oleh pengajarnya'
                ], 403);
            }
            

            $jadwal = JadwalView::getTanggalDanJenisPertemuan($kelasKuliahId);
            $kelasDibuka = Pertemuan::getLastKelasDibuka(
                $kelasKuliah['kelas_kuliah_id'], $kelasKuliah['pengajar_id'], $jadwal['tanggal']
            );

            if ($kelasDibuka->exists()) {
                // cek kelas join
                $kelasKuliahIdArr = [];

                /**
                 * Jika kelas yang dibuka dan dijoin ke kelas lain
                 */
                if ($kelasKuliah['kjoin_kelas']) {
                    $kelasKuliahId = $kelasKuliah['join_kelas_kuliah_id'];
                }

                /**
                 * Kelas-kelas yang dijoin
                 */
                $kelasKuliahIdArr = KelasKuliahJoinView::where('join_kelas_kuliah_id', $kelasKuliahId)
                    ->pluck('kelas_kuliah_id')->filter()->toArray();

                array_push($kelasKuliahIdArr, $kelasKuliahId);

                // hapus setiap pin yang ada di cache
                foreach ($kelasKuliahIdArr as $item) {
                    self::destroyRandomPIN($item);
                }

                $presensiMahasiswa = Pertemuan::getPertemuanKelasDibukaWithPresensi($kelasKuliahIdArr, $dosen['dosen_id'])
                    ->pluck('presensi')->flatten();
                $orderedPresensiMahasiswa = collect($presensiMahasiswa)->sortByDesc('masuk')->values()->all();
                $jumlahMahasiswa = $presensiMahasiswa->count();
                $jumlahMahasiswaHadir = $presensiMahasiswa->whereNotNull('masuk')->count();
                $jumlahMahasiswaBelumHadir = $presensiMahasiswa->whereNull('masuk')->count();

                Pertemuan::updateKelasDibuka($kelasKuliahIdArr, $kelasKuliah['pengajar_id'], false); // ditutup

                /**
                 * Berita acara
                 */
                $kelas = KelasKuliahJoinView::where('kelas_kuliah_id', $kelasKuliahId)->first();
                $matkul = MatKulView::where('mk_id', $kelas['mk_id'])->first();
                $beritaAcara = [
                    'kelas_kuliah_id' => $kelasKuliahId,
                    'dosen_id' => $dosen['dosen_id'],
                    'kd_mk' => trim($matkul['kd_mk']),
                    'nm_mk' => trim($matkul['nm_mk']),
                    'jml_mhs' => $jumlahMahasiswa,
                    'mhs_hdr' => $jumlahMahasiswaHadir,
                    'mhs_tdk_hdr' => $jumlahMahasiswaBelumHadir,
                    'created_at' => Carbon::now(),
                    'berita_acara' => $request->berita_acara
                ];

                BeritaAcara::insert($beritaAcara);
                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Kelas kuliah berhasil ditutup',
                    'data' => [
                        'jumlah_mahasiswa' => $jumlahMahasiswa,
                        'jumlah_mahasiswa_hadir' => $jumlahMahasiswaHadir,
                        'jumlah_mahasiswa_belum_hadir' => $jumlahMahasiswaBelumHadir,
                        'presensi_mahasiswa' => $orderedPresensiMahasiswa
                    ]
                ], 200);
            } else {
                DB::rollBack();
                return response()->json([
                    'status' => 'fail',
                    'message' => 'Belum ada kelas kuliah yang dibuka'
                ], 400);
            }
        } catch  (\Exception $e) {
            DB::rollBack();
            return ErrorHandler::handle($e);
        }
    }

    private function generateUniqueRandomPIN($kelasKuliahIdArr) {
        $implodedKelasId = implode('-', $kelasKuliahIdArr);
        $randomPIN = str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);

        /**
         * Jika PIN sebelumnya masih ada di cache,
         * maka PIN tersebut belum digunakan sama sekali.
         */
        if (Cache::has(((string) $kelasKuliahIdArr[0]))) {
            $dataPIN = Cache::get((string) $kelasKuliahIdArr[0]);

            if (is_array($dataPIN)) {
                $randomPIN = $dataPIN['pin'];
            } else {
                $randomPIN = Cache::get((string) $kelasKuliahIdArr[0]);
            }
        } else {
            // simpan pin baru
            foreach ($kelasKuliahIdArr as $item) {
                $dataPIN = [
                    'pin' => $randomPIN,
                    'type_pin' => 'unique',
                ];

                Cache::put(((string) $item), $dataPIN);
            }
        }

        return [
            'qrcode_value' => config('app.url') . 'api/kelas-kuliah/'
                . 'mahasiswa/presensi/qrcode?kelas=' . $implodedKelasId . '&pin=' . $randomPIN
                . '&unique_pin=true',
            'pin' => $randomPIN,
            'type_pin' => 'unique',
        ];
    }

    private function generateSingleRandomPIN($kelasKuliahIdArr) {
        $randomPIN = str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $implodedKelasId = implode('-', $kelasKuliahIdArr);

        // simpan pin ke cache dengan key kelas kuliah id
        foreach ($kelasKuliahIdArr as $item) {
            $key = (string) $item;
            Cache::put($key, $randomPIN);
        }

        return [
            'qrcode_value' => config('app.url') . 'api/kelas-kuliah/'
                . 'mahasiswa/presensi/qrcode?kelas=' . $implodedKelasId . '&pin=' . $randomPIN,
            'pin' => $randomPIN,
            'type_pin' => 'single',
        ];
    }

    private function destroyRandomPIN($kelasKuliahId) {
        $key = (string) $kelasKuliahId;
        Cache::forget($key);
    }

    private function createDaftarKehadiran($pertemuan, $kelasKuliahId) {
        $insertedPertemuanId = Pertemuan::create($pertemuan)->pertemuan_id;
        $tempMahasiswa= JadwalView::getJadwalWithMahasiswa($kelasKuliahId)->toArray();
        $mahasiswa = array_map(function ($item) {
            return $item['mahasiswa'];
        }, $tempMahasiswa);
        $presensi = [];

        foreach ($mahasiswa as $index => $mhs) {
            $presensi[$index]['pertemuan_id'] = $insertedPertemuanId;
            $presensi[$index]['mhs_id'] = $mhs['mhs_id'];
            $presensi[$index]['nim'] = $mhs['nim'];
            $presensi[$index]['nm_mhs'] = $mhs['nm_mhs'];
            $presensi[$index]['pin'] = null;
            $presensi[$index]['masuk'] = null;
        }

        Presensi::insert($presensi);
    }
}
