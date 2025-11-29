<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorHandler;
use App\Models\JurusanView;
use App\Models\KampusView;
use App\Models\Kuesioner\KuesionerPerkuliahan;
use App\Models\TahunAjaran;
use Illuminate\Http\Request;

// ? Models - view
use App\Models\TahunAjaranView;
use App\Models\Users\Mahasiswa;
use PhpParser\Node\Expr\FuncCall;

class TahunAjaranController extends Controller
{
    public function getTahunAjaran(Request $request)
    {
        try {
            $isDosen = auth()->user()->is_dosen;
            $user = $this->getUserAuth();

            if ($isDosen) {
                // TODO: jika user adalah dosen
                return response()->json([
                    'message' => 'Belum difungsikan'
                ], 200);
            }

            // user adalah mahasiswa
            $mahasiswa = $user;
            $tahunAjaran = TahunAjaranView::getTahunAjaran($mahasiswa);

            return $this->successfulResponseJSON([
                'tahun_ajaran' => $tahunAjaran,
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getTahunAjaranAktifByMhsId(Request $request, int $mhs_id) {
        $mahasiswa = Mahasiswa::where('mhs_id', $mhs_id)->first();
        if (!$mahasiswa) {
            return response()->json([
                'success' => false,
                'message' => 'Mahasiswa tidak ditemukan',
                'data' => null
            ], 404);
        }

        $tahunAjaran = TahunAjaranView::getTahunAjaran($mahasiswa);

        return response()->json([
            'success' => true,
            'data' => $tahunAjaran
        ]);
    }

    /**
     * Get tahun ajaran aktif untuk si kuesioner
     * Tahun ajaran yang didapat haruslah yang memiliki daftar mata kuliah
     *
     * Digunakan oleh admin
     */
    public function getTahunAjaranAktifForKuesioner() {
        try {
            $tahunAjaranArr = TahunAjaranView::getTahunAjaranWithKRS()->filter(function ($item) {
                // if ($item['krs']->count() > 0) {
                    return $item;
                // }
            })->flatten();

            $filteredTahunAjaran = [];

            foreach ($tahunAjaranArr as $index => $item) {
                $jurusan = JurusanView::where('jur_id', $item['jur_id'])->first();
                $kampus = KampusView::where('kd_kampus', $item['kd_kampus'])
                    ->select('kd_kampus', 'lokasi', 'alamat')
                    ->first();

                /**
                 * cek kuesioner tersedia untuk tahun ajarannya
                 */
                $kuesioner = KuesionerPerkuliahan::where('tahun_id', $item['tahun_id'])->first();
                $isKuesionerAvailable = $kuesioner ? true : false;

                $tahunAjaran = [
                    'tahun_id' => $item['tahun_id'],
                    'jur_id' => $item['jur_id'],
                    'tahun' => $item['tahun'],
                    'smt' => $item['smt'],
                    'jns_mhs' => $item['jns_mhs'],
                    'kd_kampus' => $item['kd_kampus'],
                    'sts_ta' => 'B',
                    'uraian' => trim($item['uraian']),
                    'ket_smt' => $item['smt'] == 1 ? 'Ganjil'
                        : ($item['smt'] == 2 ? 'Genap' : 'Tambahan'),
                    'ket_jns_mhs' => $item['jns_mhs'] == 'R' ? 'Reguler'
                        : ($item['jns_mhs'] == 'K' ? 'Karyawan' : 'Eksekutif'),
                    'detail_jurusan' => $jurusan,
                    'detail_kampus' => $kampus,
                    'kuesioner_open' => $isKuesionerAvailable,
                ];

                $filteredTahunAjaran[$index] = $tahunAjaran;
            }

            return $this->successfulResponseJSON([
                'tahun_ajaran' => $filteredTahunAjaran
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
    
    public function getTahunAjaranAktifForBerita() {
        try {
            $tahunAjaranArr = TahunAjaranView::getTahunAjaranWithKRS()->filter(function ($item) {
                // if ($item['krs']->count() > 0) {
                    return $item;
                // }
            })->flatten();

            $filteredTahunAjaran = [];

            foreach ($tahunAjaranArr as $index => $item) {
                $jurusan = JurusanView::where('jur_id', $item['jur_id'])->first();
                $kampus = KampusView::where('kd_kampus', $item['kd_kampus'])
                    ->select('kd_kampus', 'lokasi', 'alamat')
                    ->first();

                /**
                 * cek kuesioner tersedia untuk tahun ajarannya
                 */
                $kuesioner = KuesionerPerkuliahan::where('tahun_id', $item['tahun_id'])->first();
                $isKuesionerAvailable = $kuesioner ? true : false;

                $tahunAjaran = [
                    'tahun_id' => $item['tahun_id'],
                    'jur_id' => $item['jur_id'],
                    'tahun' => $item['tahun'],
                    'smt' => $item['smt'],
                    'jns_mhs' => $item['jns_mhs'],
                    'kd_kampus' => $item['kd_kampus'],
                    'sts_ta' => 'B',
                    'uraian' => trim($item['uraian']),
                    'ket_smt' => $item['smt'] == 1 ? 'Ganjil'
                        : ($item['smt'] == 2 ? 'Genap' : 'Tambahan'),
                    'ket_jns_mhs' => $item['jns_mhs'] == 'R' ? 'Reguler'
                        : ($item['jns_mhs'] == 'K' ? 'Karyawan' : 'Eksekutif'),
                    'detail_jurusan' => $jurusan,
                    'detail_kampus' => $kampus,
                    'kuesioner_open' => $isKuesionerAvailable,
                ];

                $filteredTahunAjaran[$index] = $tahunAjaran;
            }

            return $this->successfulResponseJSON([
                'tahun_ajaran' => $filteredTahunAjaran
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getTahunAjaranAktifV2() {
        try {
            $tahunAjaranArr = TahunAjaranView::getTahunAjaranWithKRS()->filter(function ($item) {
                return $item['krs'];
            })->map(function ($item) {
                return [
                    'tahun_id' => $item['tahun_id'],
                    'uraian' => $item['uraian']
                ];
            })
            ->sortByDesc('tahun_id')
            ->values();

            return $this->successfulResponseJSON([
                'tahun_ajaran' => $tahunAjaranArr
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getSemesterMahasiswaSekarang() {
        try {
            $mahasiswa = $this->getUserAuth();
            $tahunAjaran = TahunAjaranView::getTahunAjaran($mahasiswa);

            if(!$tahunAjaran->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Saat ini belum ada tahun ajaran yang sedang aktif!',
                    'data' => [
                        'tahun' => null,
                        'smt' => 0,
                        'keterangan_smt' => 'Belum ada, dikarenakan belum tahun ajaran aktif!',
                        'semester' => 0
                    ]
                ], 404);
            }

            $gap = $tahunAjaran['tahun'] - $mahasiswa['angkatan'];
            $semester = $tahunAjaran['smt'] === 1
                ? $gap * 2 + 1
                : $gap * 2 + 2;

            return $this->successfulResponseJSON([
                'tahun' => $tahunAjaran['tahun'],
                'smt' => $tahunAjaran['smt'],
                'keterangan_smt' => $tahunAjaran['smt'] === 1 ? 'Ganjil' : 'Genap',
                'semester'=> $semester,
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
    public function getTahunAjaranAktifNoFilter() {
        try {
           
            $tahunAjaran = TahunAjaran::get(); // Or TahunAjaranView::all(); depending on your model
            // dd($tahunAjaran);
            return $this->successfulResponseJSON([
                'tahun_ajaran' => $tahunAjaran,
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
