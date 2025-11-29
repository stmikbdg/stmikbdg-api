<?php

namespace App\Http\Controllers\Keuangan;

use App\Http\Controllers\Controller;
use App\Models\Keuangan\BiayaPerMahasiswa;
use App\Models\Keuangan\DetailPembayaran;
use App\Models\Keuangan\ManajemenBiaya;
use App\Models\Keuangan\MasterNotifikasi;
use App\Models\Keuangan\MasterPembayaran;
use App\Models\Keuangan\PenerimaBeasiswa;
use App\Models\Keuangan\TahunAkademik;
use App\Models\KRS\KRS;
use App\Models\SIKPS\MasterTahunAkademik;
use App\Models\TahunAjaran;
use App\Models\TahunAjaranView;
use App\Models\Users\Mahasiswa;
use App\Models\Users\MahasiswaView;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MhsController extends Controller
{
    const KOMPONEN_SEMESTER_PERTAMA = [1, 2, 3, 7];
    const KOMPONEN_KP = 8;
    const KOMPONEN_SKRIPSI = [9, 10];

    private function tambahBiaya(&$target, $tahunKey, $semesterKey, $biaya) {
        $target[$tahunKey][$semesterKey][] = [
            'id_biaya_per_mahasiswa' => $biaya['id_biaya_per_mahasiswa'],
            'id_nama_komponen' => $biaya['id_nama_komponen'],
            'nama_komponen' => $biaya['nama_komponen'],
            'jumlah' => $biaya['jumlah'],
            'sisa' => $biaya['sisa'],
            'created_at' => $biaya['created_at'],
        ];
    }

    public function getPenerimaBeasiswa(Request $request) {
        // Ambil Data Profile Mahasiswa dari Session
        $profile = $this->getUserAuth();
        // $account = auth()->user();


        // $kd_kampus = trim($profile['kd_kampus'] ?? '');
        // $jur_id = trim($profile['jur_id'] ?? '');
        // $jns_mhs = trim($profile['jns_mhs'] ?? '');
        // $masuk_tahun = (int) ($profile['masuk_tahun'] ?? 0);
        $mhsId = $profile['mhs_id'] ?? null;
        // $sts_mhs = $profile['sts_mhs'] ?? '';

        $tahunAktif = TahunAjaranView::getTahunAjaran($profile);
        $semesterSekarang = $tahunAktif ?? null;
        $tahunId = $semesterSekarang['tahun_id'] ?? null;

        $beasiswa = PenerimaBeasiswa::with('master_beasiswa')
            ->whereHas('master_beasiswa', function ($query) use ($tahunId) {
                $query->where('id_thn_akademik', $tahunId)->where('status', 1);
            })
            ->where('status', 1)
            ->get();

        $mahasiswa = Mahasiswa::where('sts_mhs', 'A')
            
            // ->where('kd_kampus', 'A')
            ->whereNotNull('krs_id_last')
            ->whereIn('mhs_id', $beasiswa->pluck('mhs_id')->toArray())
            ->whereHas('krs.krsMatkul.kelasKuliahJoin', function ($query) use ($tahunId) {
                $query->where('tahun_id', $tahunId);
            })
            ->with('jurusan')
            // ->pluck(['mhs_id', 'nm_mhs', 'nim', 'jurusan'])
            ->get();

        $penerima_beasiswa = $beasiswa->map(function ($item) use ($mahasiswa) {
            $item['mahasiswa'] = $mahasiswa->where('mhs_id', $item['mhs_id'])->select(['nm_mhs', 'nim', 'jurusan'])->first();
            return $item;
        })->filter(function ($item) {
            return $item['mahasiswa'] != null;
        })->values()->firstWhere(fn($b) => $b['mhs_id'] == $mhsId && $b['status'] == 1);

        return response()->json([
            'success' => true,
            'data' => $penerima_beasiswa
        ]);
    }

    public function getBiayaPerSemester(Request $request) {
        $profile = $this->getUserAuth();

        $masuk_tahun = (int) ($profile['masuk_tahun'] ?? 0);
        $mhsId = $profile['mhs_id'] ?? null;

        $manajemenBiayaData = ManajemenBiaya::with([
            'm_biaya_per_mahasiswa.m_komponen_biaya'
        ])->where('mhs_id', $mhsId)->get();

        $allTahunAkademik = TahunAkademik::getTahunAkademik([
            'status' => 1
        ]);

        $tahunGrouped = TahunAjaran::get()
            ->filter(fn($item) => $item['tahun'] >= $masuk_tahun)
            ->values();

        $pelaksanaanSemesterMap = $allTahunAkademik->flatMap(function ($ta) {
            return [
                [
                    'tahun' => $ta['thn_akademik'],
                    'smt' => 1,
                    'mulai' => Carbon::parse($ta['ganjil_mulai']),
                    'akhir' => Carbon::parse($ta['ganjil_pelaksanaan_akhir']),
                ],
                [
                    'tahun' => $ta['thn_akademik'],
                    'smt' => 2,
                    'mulai' => Carbon::parse($ta['genap_mulai']),
                    'akhir' => Carbon::parse($ta['genap_pelaksanaan_akhir']),
                ],
                [
                    'tahun' => $ta['thn_akademik'],
                    'smt' => 3,
                    'mulai' => Carbon::parse($ta['antara_mulai']),
                    'akhir' => Carbon::parse($ta['antara_pelaksanaan_akhir']),
                ]
            ];
        });

        $tahunAkademikList = $tahunGrouped->map(function ($semester) use ($pelaksanaanSemesterMap) {
            $pelaksanaan = $pelaksanaanSemesterMap->first(fn($p) =>
                $p['tahun'] == $semester['tahun'] && (int)$p['smt'] === (int)$semester['smt']
            );

            $semester['mulai'] = $pelaksanaan['mulai'] ?? null;
            $semester['akhir'] = $pelaksanaan['akhir'] ?? null;

            return $semester;
        });

        $BiayaPerMahasiswa = $manajemenBiayaData->flatMap(function ($manajemen) {
            return $manajemen->m_biaya_per_mahasiswa->map(function ($b) {
                $komponen = $b->m_komponen_biaya;

                return [
                    'id_biaya_per_mahasiswa' => $b->id_biaya_per_mahasiswa,
                    'id_nama_komponen' => $komponen->id_nama_komponen ?? null,
                    'nama_komponen' => $komponen->nama_komponen ?? 'Tidak Diketahui',
                    'jumlah' => (int) $b->jumlah,
                    'sisa' => (int) $b->sisa,
                    'created_at' => $b->created_at,
                    'status' => $b->status,
                ];
            });
        });

        $biayaPerSemester = [];

        foreach ($tahunAkademikList as $semester) {
            $semesterKey = match ((int) $semester['smt']) {
                1 => 'Ganjil', 2 => 'Genap', 3 => 'Antara', default => 'Tidak Diketahui'
            };
            $tahunKey = $semester['tahun'];
            $kodeMKDiambil = $this->getKodeMKPerSemester($mhsId, $tahunKey, $semesterKey, $allTahunAkademik);
            $isSemesterPertama = ((int) explode('/', $tahunKey)[0] === $masuk_tahun && $semesterKey === 'Ganjil');

            // $biayaPerSemester[$tahunKey][$semesterKey][] = $BiayaPerMahasiswa;

            $biaya_kp = $BiayaPerMahasiswa
                ->filter(function ($biaya) use ($kodeMKDiambil) {
                    return $biaya['id_nama_komponen'] === self::KOMPONEN_KP && $kodeMKDiambil->contains(fn($k) => in_array($k, ['SI1600', 'IF1600']));
                })->values();

            $biaya_skripsi = $BiayaPerMahasiswa
                ->filter(function ($biaya) use ($kodeMKDiambil) {
                    return in_array($biaya['id_nama_komponen'], self::KOMPONEN_SKRIPSI) && $kodeMKDiambil->contains(fn($k) => in_array($k, ['SI1800', 'IF1800']));
                })->values();

            $biaya_semester_pertama = $BiayaPerMahasiswa
                ->filter(function ($biaya) use ($isSemesterPertama) {
                    return in_array($biaya['id_nama_komponen'], self::KOMPONEN_SEMESTER_PERTAMA) && $isSemesterPertama;
                })->values();

            $biaya_tanggal = $BiayaPerMahasiswa
                ->filter(function ($biaya) use ($semester) {
                    return $biaya['created_at']->between($semester['mulai'], $semester['akhir']);
                });

            $data = [
                'biaya_tanggal' => $biaya_tanggal,
                'biaya_kp' => $biaya_kp,
                'biaya_skripsi' => $biaya_skripsi,
                'biaya_semester_pertama' => $biaya_semester_pertama,
                'kodeMKDiAmbil' => $kodeMKDiambil
            ];

            

            $merged_data = collect(); // mulai dengan empty collection

            // selalu masuk biaya_tanggal
            $merged_data = $merged_data->concat($data['biaya_tanggal']);

            // hanya tambahkan biaya_semester_pertama kalau semester pertama
            $merged_data = $merged_data->when($isSemesterPertama, function ($col) use ($data) {
                return $col->concat($data['biaya_semester_pertama']);
            }, function ($col) {
                // kalau bukan semester pertama, buang semua komponen SEMESTER PERTAMA
                return $col->reject(fn($item) => in_array($item['id_nama_komponen'], self::KOMPONEN_SEMESTER_PERTAMA));
            });

            // hanya tambahkan biaya_kp kalau ada datanya
            $merged_data = $merged_data->when($data['biaya_kp']->isNotEmpty(), function ($col) use ($data) {
                return $col->concat($data['biaya_kp']);
            }, function ($col) {
                return $col->reject(fn($item) => $item['id_nama_komponen'] === self::KOMPONEN_KP);
            });

            // hanya tambahkan biaya_skripsi kalau ada datanya
            $merged_data = $merged_data->when($data['biaya_skripsi']->isNotEmpty(), function ($col) use ($data) {
                return $col->concat($data['biaya_skripsi']);
            }, function ($col) {
                return $col->reject(fn($item) => in_array($item['id_nama_komponen'], self::KOMPONEN_SKRIPSI));
            });

            $final = $merged_data
                ->unique('id_biaya_per_mahasiswa')
                ->values();

            $biayaPerSemester[$tahunKey][$semesterKey] = $final;

        }

        return response()->json([
            'success' => true,
            'data' => $biayaPerSemester
        ]);
    }


    private function getKodeMKPerSemester($mhs_id, $tahun, $semester, $tahunAkademikList) {
        $ta = $tahunAkademikList->firstWhere('thn_akademik', $tahun);
        // dd($ta);
        if (!$ta) return collect();

        $semesterKey = strtolower($semester);
        $mulaiKey = $semesterKey . '_pelaksanaan_mulai';
        $akhirKey = $semesterKey . '_pelaksanaan_akhir';

        if (!isset($ta[$mulaiKey], $ta[$akhirKey])) return collect();

        $mulai = Carbon::parse($ta[$mulaiKey]);
        $akhir = Carbon::parse($ta[$akhirKey]);

        // dd($mulai, $akhir);
        // dd(
        //     KRS::where('mhs_id', $mhs_id)
        //         ->with('krsMatkul.matakuliah')
                
        //         ->get()
        //         ->toArray()
        // );

        return KRS::where('mhs_id', $mhs_id)
            ->whereBetween('tanggal', [$mulai, $akhir])
            ->with('krsMatkul.matakuliah')
            ->get()
            ->flatMap(fn($krs) => $krs->krsMatkul)
            ->pluck('matakuliah.kd_mk');
            // ->filter();
    }

    public function getPembayaranList (Request $request) {

        $profile = $this->getUserAuth();
        
        $mhsId = $profile['mhs_id'] ?? null;

        Carbon::setLocale('id');
        $pembayaranList = MasterPembayaran::with('detailPembayaran.biayaPerMahasiswa.m_komponen_biaya')
            ->where('mhs_id', $mhsId)
            ->orderBy('tanggal_pembayaran')
            ->get()
            ->groupBy(fn($item) => $item->tahun . '-' . $item->smt)
            ->map(fn($group) => $group->map(fn($item) => [
                'id_pembayaran' => $item->id_pembayaran,
                'total_pembayaran' => $item->total_pembayaran,
                'tanggal_pembayaran' => Carbon::parse($item->tanggal_pembayaran)->translatedFormat('d F Y'),
                'bukti_pembayaran' => $item->bukti_pembayaran,
                'form_penangguhan' => $item->form_penangguhan,
                'metode_pembayaran' => $item->metode_pembayaran,
                'catatan' => $item->catatan,
                'bank' => $item->bank,
                'no_transaksi' => $item->no_transaksi,
                'no_kwitansi' => $item->no_kwitansi,
                'status_verifikasi' => $item->detailPembayaran->every(fn($d) => $d->status_verifikasi),
                'detail' => $item->detailPembayaran->map(fn($d) => [
                    'nama_komponen' => $d->biayaPerMahasiswa->m_komponen_biaya->nama_komponen ?? 'N/A',
                    'nominal' => $d->nominal_bayar,
                    'status_verifikasi' => $d->status_verifikasi,
                ])
        ]));

        return response()->json([
            'success' => true,
            'data' => $pembayaranList
        ]);
    }


    public function pembayaran(Request $request) {
        DB::beginTransaction();

        try {
            // Validasi input
            $request->validate([
                'mhs_id' => 'required|integer',
                'total_pembayaran' => 'required|string',
                'metode_pembayaran' => 'required|in:tunai,non_tunai',
                'bukti_pembayaran' => 'file|mimes:jpg,jpeg,png,pdf|max:2048',
                'form_penangguhan' => 'file|mimes:jpg,jpeg,png,pdf|max:2048',
                'bank' => 'nullable|string',
                'no_transaksi' => 'nullable|string',
                'no_kwitansi' => 'nullable|string',
            ]);

            // Upload bukti pembayaran
            $buktiPath = null;
            if ($request->hasFile('bukti_pembayaran')) {
                $original = $request->file('bukti_pembayaran')->getClientOriginalName();
                $filename = pathinfo($original, PATHINFO_FILENAME);
                $ext = $request->file('bukti_pembayaran')->getClientOriginalExtension();
                $finalName = $filename . '_' . time() . '.' . $ext;

                $file_response = $this->uploadFile('/keuangan/mahasiswa/bukti_pembayaran', $finalName, $request->file('bukti_pembayaran'));
                if($file_response['success']) {
                    $buktiPath = $file_response['data']['url'];
                }

                // $request->file('bukti_pembayaran')->storeAs('public/bukti_pembayaran', $finalName);
                // $buktiPath = $finalName; // Simpan hanya nama file
            }

            // Upload form penangguhan
            $formPenangguhanPath = null;
            if ($request->hasFile('form_penangguhan')) {
                $originalForm = $request->file('form_penangguhan')->getClientOriginalName();
                $filenameForm = pathinfo($originalForm, PATHINFO_FILENAME);
                $extForm = $request->file('form_penangguhan')->getClientOriginalExtension();
                $finalFormName = $filenameForm . '_' . time() . '.' . $extForm;

                $file_response = $this->uploadFile('/keuangan/mahasiswa/form_penangguhan', $finalFormName, $request->file('form_penangguhan'));
                if($file_response['success']) {
                    $formPenangguhanPath = $file_response['data']['url'];
                }

                // $request->file('form_penangguhan')->storeAs('public/form_penangguhan', $finalFormName);
                // $formPenangguhanPath = $finalFormName; // Simpan hanya nama file
            }

            $semesterTeks = $request->input('smt');
            $smt = match (strtolower($semesterTeks)) {
                'ganjil' => 1,
                'genap' => 2,
                'antara' => 3,
                default => null,
            };

            // Simpan ke tabel pembayaran
            $pembayaran = MasterPembayaran::create([
                'mhs_id' => $request->input('mhs_id'),
                'total_pembayaran' => str_replace('.', '', $request->input('total_pembayaran')),
                'tanggal_pembayaran' => now(),
                'bukti_pembayaran' => $buktiPath,
                'form_penangguhan' => $formPenangguhanPath, // disimpan di kolom form_penangguhan
                'catatan' => null,
                'bank' => $request->input('bank'),
                'no_transaksi' => $request->input('no_transaksi'),
                'no_kwitansi' => $request->input('no_kwitansi'),
                'metode_pembayaran' => $request->input('metode_pembayaran'),
                'tahun' => $request->input('tahun'),
                'tahun_id' => $request->input('tahun_id'),
                'smt' => $smt,
                'termin' => 1 // Asumsi termin 1 untuk pembayaran pertama
            ]);

            // Simpan detail pembayaran ke DetailPembayaran
            $terpilih = $request->input('komponen_terpilih', []);
            foreach ($terpilih as $index) {
                $idBiaya = $request->input("id_biaya_per_mahasiswa.$index");
                $nominal = (int) str_replace('.', '', $request->input("nominal_bayar.$index"));

                $biaya = BiayaPerMahasiswa::with('m_komponen_biaya')->find($idBiaya);
                if (!$biaya || !$biaya->m_komponen_biaya) {
                    throw new \Exception("Komponen biaya tidak ditemukan untuk ID biaya $idBiaya");
                }

                $idNamaKomponen = $biaya->m_komponen_biaya->id_nama_komponen;

                $is_kp = $idNamaKomponen == 8;
                $is_skripsi = $idNamaKomponen == 9;
                $is_lainnya = !$is_kp && !$is_skripsi;

                DetailPembayaran::create([
                    'id_pembayaran' => $pembayaran->id_pembayaran,
                    'id_biaya_per_mahasiswa' => $idBiaya,
                    'mhs_id' => $request->input('mhs_id'),
                    'nominal_bayar' => $nominal,
                    'status_verifikasi' => false,
                    'is_kp' => $is_kp,
                    'is_skripsi' => $is_skripsi,
                    'is_lainnya' => $is_lainnya
                ]);
            }

            MasterNotifikasi::create([
                'id_pembayaran' => $pembayaran->id_pembayaran,
                'status' => 1,
                'title' => 'Pembayaran Baru',
                'message' => 'Terdapat pembayaran yang harus diverifikasi.'
            ]);

            // PembayaranBaruEvent::dispatch($notifikasi);

            // event(new \App\Events\PembayaranBaruEvent($notifikasi));

            DB::commit();

            // Notifikasi via WhatsApp
            // Ambil data mahasiswa
            // $profile = session('profile');
            // $nim = $profile['nim'] ?? 'N/A';
            // $nama = $profile['nama'] ?? 'N/A';

            // Format nominal ke format rupiah
            // $total = number_format($pembayaran->total_pembayaran, 0, ',', '.');

            // $url = 'https://keuangan.stmik-bandung.ac.id/';

            // Format pesan WA
            // $pesan = "Terdapat pembayaran baru dari $nim - $nama sebesar Rp $total yang harus diverifikasi.\n\nCek detail pembayaran di: $url";
           
            // $curl = curl_init();
            // $token = 'UjFatUeiguLWAAyyt3FP';
            // $number = '6281223052488';

            // curl_setopt_array($curl, array(
            //     CURLOPT_URL => 'https://api.fonnte.com/send',
            //     CURLOPT_RETURNTRANSFER => true,
            //     CURLOPT_ENCODING => '',
            //     CURLOPT_MAXREDIRS => 10,
            //     CURLOPT_TIMEOUT => 0,
            //     CURLOPT_FOLLOWLOCATION => true,
            //     CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            //     CURLOPT_CUSTOMREQUEST => 'POST',
            //     CURLOPT_POSTFIELDS => array(
            //         'target' => $number,
            //         'message' => $pesan,
            //     ),
            //     CURLOPT_HTTPHEADER => array(
            //         "Authorization: $token",
            //     ),
            // ));

            // curl_exec($curl);
            // if (curl_errno($curl)) {
            //     $error_msg = curl_error($curl);
            // }
            // curl_close($curl);

            // if (isset($error_msg)) {
            //     Log::error("Fonnte Error: " . $error_msg);
            // }

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran berhasil disimpan. Silahkan tunggu verifikasi dari Admin Keuangan.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan pembayaran: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cek_pembayaran_mhs(Request $request) {
        $profile = $this->getUserAuth();

        $mhsId = $profile['mhs_id'] ?? null;
        $allTahunAkademik = TahunAkademik::getTahunAkademik([
            'status' => 1
        ]);
        $today = Carbon::now();

        $terminAktif = null;

        // 1. Cari termin berdasarkan tanggal
        foreach ($allTahunAkademik as $ta) {
            if ($today->between(Carbon::parse($ta['ganjil_mulai']), Carbon::parse($ta['ganjil_pelaksanaan_akhir']))) {
                $terminAktif = $ta['termin'];
                break;
            }
            if ($today->between(Carbon::parse($ta['genap_mulai']), Carbon::parse($ta['genap_pelaksanaan_akhir']))) {
                $terminAktif = $ta['termin'];
                break;
            }
            if ($today->between(Carbon::parse($ta['antara_mulai']), Carbon::parse($ta['antara_pelaksanaan_akhir']))) {
                $terminAktif = $ta['termin'];
                break;
            }
        }

        // 2. Ambil tahun ajaran aktif (untuk ambil tahun_id)
        $tahunAktif = TahunAjaranView::getTahunAjaran($profile);
        $semesterSekarang = $tahunAktif ?? null;

        $tahunId = $semesterSekarang['tahun_id'] ?? null;

        // 3. Kalau tidak ada termin aktif → ambil termin terakhir
        if (!$terminAktif) {
            $terminAktif = collect($allTahunAkademik)->max('termin');
        }

        // 4. Cek apakah sudah bayar di termin yang ketemu
        $sudahBayar = MasterPembayaran::where('mhs_id', $mhsId)
            ->where('tahun_id', $tahunId)
            ->where('termin', $terminAktif)
            ->exists();

        return response()->json([
            'success'  => $sudahBayar,                   // true/false
            'message' => $sudahBayar ? 'Sudah Bayar' : 'Belum Bayar',
            'termin'  => $terminAktif
        ]);
    }

    public function getTotalSKS(Request $request) {

        $profile = $this->getUserAuth();
        
        $mhsId = $profile['mhs_id'] ?? null;

        $now = Carbon::now();
        $tahunAkademikList = TahunAkademik::getTahunAkademik([
            'status' => 1
        ])->toArray(); // ambil dari service

        $semesterAktif = null;

        foreach ($tahunAkademikList as $ta) {
            if ($now->between(Carbon::parse($ta['ganjil_pelaksanaan_mulai']), Carbon::parse($ta['ganjil_pelaksanaan_akhir']))) {
                $semesterAktif = ['mulai' => $ta['ganjil_pelaksanaan_mulai'], 'akhir' => $ta['ganjil_pelaksanaan_akhir']];
                break;
            } elseif ($now->between(Carbon::parse($ta['genap_pelaksanaan_mulai']), Carbon::parse($ta['genap_pelaksanaan_akhir']))) {
                $semesterAktif = ['mulai' => $ta['genap_pelaksanaan_mulai'], 'akhir' => $ta['genap_pelaksanaan_akhir']];
                break;
            } elseif ($now->between(Carbon::parse($ta['antara_pelaksaan_mulai']), Carbon::parse($ta['antara_pelaksanaan_akhir']))) {
                $semesterAktif = ['mulai' => $ta['antara_pelaksaan_mulai'], 'akhir' => $ta['antara_pelaksanaan_akhir']];
                break;
            }
        }           

        if (!$semesterAktif) {
            return response()->json(['total_sks' => 0]);
        }

        // Ambil semua KRS yang milik mahasiswa dan berada dalam rentang semester aktif
        $krsList = Krs::with(['krsMatkul.mataKuliah'])
            ->where('mhs_id', $mhsId)
            ->whereBetween('tanggal', [$semesterAktif['mulai'], $semesterAktif['akhir']])
            ->get();

        $totalSks = 0;

        foreach ($krsList as $krs) {
            foreach ($krs->krsMatkul as $krsMk) {
                $totalSks += $krsMk->mataKuliah->sks ?? 0;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_sks' => $totalSks
            ]
        ]);
    }

}
