<?php

namespace App\Http\Controllers\Keuangan;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\Keuangan\TahunAkademik;
use App\Models\KRS\NilaiAkhirView;
use App\Models\SIKPS\MasterTahunAkademik;
use App\Models\Users\Mahasiswa;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class KeuanganController extends Controller
{

    // private function parseFilters(array $filters = []): array {
    //     $parsed = [];

    //     foreach ($filters as $key => $value) {
    //         if (is_string($value) && str_contains($value, ',')) {
    //             // Convert comma-separated string into array
    //             $parsed[$key] = explode(',', $value);
    //         } else {
    //             $parsed[$key] = $value;
    //         }
    //     }

    //     return $parsed;
    // }

    public function getAllMahasiswaAktif(Request $request) {
        $query = $this->parseFilters($request->query('filters') ?? []);
        $mahasiswa = Mahasiswa::getAllMahasiswa($query)->toArray();

        return response()->json([
            'success' => true,
            'query' => $query,
            'data' => $mahasiswa
        ], 200);
    }

    public function getAllTahunAngkatan(Request $request) {

        $tahun_angkatan = Mahasiswa::getAllUniqueByColumns([
            'masuk_tahun'
        ]);

        

        return response()->json([
            'success' => true,
            'data' => $tahun_angkatan
        ], 200);
    }

    public function getTahunAkademik(Request $request) {

        $tahun_akademik = TahunAkademik::getTahunAkademik([
            'status' => 1
        ])->toArray();

        return response()->json([
            'success' => true,
            'data' => $tahun_akademik
        ], 200);
    }

    public function tahunAkademik_create(Request $request) {

        try {
            // $request->validate([
            //     'thn_akademik' => 'required|string|max:4',
            //     'ganjil_mulai' => 'required|date',
            //     'ganjil_akhir' => 'required|date|after_or_equal:ganjil_mulai',
            //     'genap_mulai' => 'required|date',
            //     'genap_akhir' => 'required|date|after_or_equal:genap_mulai',
            //     'antara_mulai' => 'required|date',
            //     'antara_akhir' => 'required|date|after_or_equal:antara_mulai',
            //     'ganjil_pelaksanaan_mulai' => 'required|date',
            //     'ganjil_pelaksanaan_akhir' => 'required|date|after_or_equal:ganjil_pelaksanaan_mulai',
            //     'genap_pelaksanaan_mulai' => 'required|date',
            //     'genap_pelaksanaan_akhir' => 'required|date|after_or_equal:genap_pelaksanaan_mulai',
            //     'antara_pelaksanaan_mulai' => 'required|date',
            //     'antara_pelaksanaan_akhir' => 'required|date|after_or_equal:antara_pelaksanaan_mulai',
            //     'status' => 'required|integer|in:0,1',
            //     'termin' => 'required|integer'
            // ]);
    
            // $body = $request->only((new TahunAkademik)->getFillable());
    
            // $data = TahunAkademik::create($body);
    
            // return response()->json([
            //     'success' => true,
            //     'message' => 'Data tahun akademik berhasil ditambahkan.',
            //     'data' => $data
            // ], 200);

            // Ubah format tanggal dd/mm/yyyy -> Y-m-d
            // $request->merge([
            //     'ganjil_mulai'                => $request->ganjil_mulai ? Carbon::createFromFormat('d/m/Y', $request->ganjil_mulai)->format('Y-m-d') : null,
            //     'ganjil_akhir'                => $request->ganjil_akhir ? Carbon::createFromFormat('d/m/Y', $request->ganjil_akhir)->format('Y-m-d') : null,
            //     'genap_mulai'                 => $request->genap_mulai ? Carbon::createFromFormat('d/m/Y', $request->genap_mulai)->format('Y-m-d') : null,
            //     'genap_akhir'                 => $request->genap_akhir ? Carbon::createFromFormat('d/m/Y', $request->genap_akhir)->format('Y-m-d') : null,
            //     'antara_mulai'                => $request->antara_mulai ? Carbon::createFromFormat('d/m/Y', $request->antara_mulai)->format('Y-m-d') : null,
            //     'antara_akhir'                => $request->antara_akhir ? Carbon::createFromFormat('d/m/Y', $request->antara_akhir)->format('Y-m-d') : null,
            //     'ganjil_pelaksanaan_mulai'    => $request->ganjil_pelaksanaan_mulai ? Carbon::createFromFormat('d/m/Y', $request->ganjil_pelaksanaan_mulai)->format('Y-m-d') : null,
            //     'ganjil_pelaksanaan_akhir'    => $request->ganjil_pelaksanaan_akhir ? Carbon::createFromFormat('d/m/Y', $request->ganjil_pelaksanaan_akhir)->format('Y-m-d') : null,
            //     'genap_pelaksanaan_mulai'     => $request->genap_pelaksanaan_mulai ? Carbon::createFromFormat('d/m/Y', $request->genap_pelaksanaan_mulai)->format('Y-m-d') : null,
            //     'genap_pelaksanaan_akhir'     => $request->genap_pelaksanaan_akhir ? Carbon::createFromFormat('d/m/Y', $request->genap_pelaksanaan_akhir)->format('Y-m-d') : null,
            //     'antara_pelaksanaan_mulai'    => $request->antara_pelaksanaan_mulai ? Carbon::createFromFormat('d/m/Y', $request->antara_pelaksanaan_mulai)->format('Y-m-d') : null,
            //     'antara_pelaksanaan_akhir'    => $request->antara_pelaksanaan_akhir ? Carbon::createFromFormat('d/m/Y', $request->antara_pelaksanaan_akhir)->format('Y-m-d') : null,
            // ]);

            // Baru lakukan validasi
            $request->validate([
                'thn_akademik'   => 'required|string|max:5',
                'termin'         => 'required',
                'ganjil_mulai'   => 'required|date',
                'ganjil_akhir'   => 'required|date|after_or_equal:ganjil_mulai',
                'genap_mulai'    => 'required|date',
                'genap_akhir'    => 'required|date|after_or_equal:genap_mulai',
                'antara_mulai'   => 'nullable|date',
                'antara_akhir'   => 'nullable|date|after_or_equal:antara_mulai',
                'ganjil_pelaksanaan_mulai'   => 'required|date',
                'ganjil_pelaksanaan_akhir'   => 'required|date|after_or_equal:ganjil_pelaksanaan_mulai',
                'genap_pelaksanaan_mulai'    => 'required|date',
                'genap_pelaksanaan_akhir'    => 'required|date|after_or_equal:genap_pelaksanaan_mulai',
                'antara_pelaksanaan_mulai'   => 'nullable|date',
                'antara_pelaksanaan_akhir'   => 'nullable|date|after_or_equal:antara_pelaksanaan_mulai',
            ]);

            // Ambil semua tahun akademik dari tahunAkademikService API
            // $allTahunAkademik = $this->tahunAkademikService->getTahunAkademik(); // pastikan ini mengembalikan array

            // Cek apakah tahun akademik dan termin yang sama dan aktif (status = 1) sudah ada
            // $existing = MasterTahunAkademik::->first(function ($item) use ($request) {
            //     return $item['thn_akademik'] == $request->thn_akademik 
            //         && $item['termin'] == $request->termin 
            //         && $item['status'] == 1;
            // });

            $existing = TahunAkademik::where('thn_akademik', $request->thn_akademik)
                ->where('termin', $request->termin)
                ->where('status', 1)
                ->exists();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Periode ' . $request->thn_akademik . ' Termin ' . $request->termin . ' sudah ada!'
                ], 400);
            }

            // Tambah data baru
            $data = [
                'thn_akademik' => $request->thn_akademik,
                'termin'       => $request->termin,
                'ganjil_mulai' => $request->ganjil_mulai,
                'ganjil_akhir' => $request->ganjil_akhir,
                'genap_mulai'  => $request->genap_mulai,
                'genap_akhir'  => $request->genap_akhir,
                'antara_mulai' => $request->antara_mulai,
                'antara_akhir' => $request->antara_akhir,
                'ganjil_pelaksanaan_mulai' => $request->ganjil_pelaksanaan_mulai,
                'ganjil_pelaksanaan_akhir' => $request->ganjil_pelaksanaan_akhir,
                'genap_pelaksanaan_mulai'  => $request->genap_pelaksanaan_mulai,
                'genap_pelaksanaan_akhir'  => $request->genap_pelaksanaan_akhir,
                'antara_pelaksanaan_mulai' => $request->antara_pelaksanaan_mulai,
                'antara_pelaksanaan_akhir' => $request->antara_pelaksanaan_akhir,
                'status'       => 1,
            ];

            // $response = $this->tahunAkademikService->postTahunAkademik($data);

            // if ($response['status'] === 'success') {
            //     return response()->json([
            //         'success' => true,
            //         'message' => 'Periode pembayaran berhasil ditambahkan!'
            //     ]);
            // }

            $data = TahunAkademik::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Berhasil menambahkan tahun akademik yang baru',
                'data' => $data
            ]);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ], 500);
        }
    }

    public function tahunAkademik_update(Request $request, int $id_thn_akademik) {
        try {
        //    $request->validate([
        //         'thn_akademik' => 'required|string|max:4',
        //         'ganjil_mulai' => 'required|date',
        //         'ganjil_akhir' => 'required|date|after_or_equal:ganjil_mulai',
        //         'genap_mulai' => 'required|date',
        //         'genap_akhir' => 'required|date|after_or_equal:genap_mulai',
        //         'antara_mulai' => 'required|date',
        //         'antara_akhir' => 'required|date|after_or_equal:antara_mulai',
        //         'ganjil_pelaksanaan_mulai' => 'required|date',
        //         'ganjil_pelaksanaan_akhir' => 'required|date|after_or_equal:ganjil_pelaksanaan_mulai',
        //         'genap_pelaksanaan_mulai' => 'required|date',
        //         'genap_pelaksanaan_akhir' => 'required|date|after_or_equal:genap_pelaksanaan_mulai',
        //         'antara_pelaksanaan_mulai' => 'required|date',
        //         'antara_pelaksanaan_akhir' => 'required|date|after_or_equal:antara_pelaksanaan_mulai',
        //         'status' => 'required|integer|in:0,1',
        //         'termin' => 'required|integer'
        //     ]);
    
        //     $payload = $request->only((new TahunAkademik)->getFillable());
    
        //     $data = TahunAkademik::findOrFail($id_thn_akademik);

        //     $data->update($payload);
    
        //     return response()->json([
        //         'success' => true,
        //         'message' => 'Data tahun akademik berhasil diubah.',
        //         'data' => $data
        //     ], 200);

        // Ubah format tanggal dd/mm/yyyy -> Y-m-d
            // $request->merge([
            //     'ganjil_mulai'                => $request->ganjil_mulai ? Carbon::createFromFormat('d/m/Y', $request->ganjil_mulai)->format('Y-m-d') : null,
            //     'ganjil_akhir'                => $request->ganjil_akhir ? Carbon::createFromFormat('d/m/Y', $request->ganjil_akhir)->format('Y-m-d') : null,
            //     'genap_mulai'                 => $request->genap_mulai ? Carbon::createFromFormat('d/m/Y', $request->genap_mulai)->format('Y-m-d') : null,
            //     'genap_akhir'                 => $request->genap_akhir ? Carbon::createFromFormat('d/m/Y', $request->genap_akhir)->format('Y-m-d') : null,
            //     'antara_mulai'                => $request->antara_mulai ? Carbon::createFromFormat('d/m/Y', $request->antara_mulai)->format('Y-m-d') : null,
            //     'antara_akhir'                => $request->antara_akhir ? Carbon::createFromFormat('d/m/Y', $request->antara_akhir)->format('Y-m-d') : null,
            //     'ganjil_pelaksanaan_mulai'    => $request->ganjil_pelaksanaan_mulai ? Carbon::createFromFormat('d/m/Y', $request->ganjil_pelaksanaan_mulai)->format('Y-m-d') : null,
            //     'ganjil_pelaksanaan_akhir'    => $request->ganjil_pelaksanaan_akhir ? Carbon::createFromFormat('d/m/Y', $request->ganjil_pelaksanaan_akhir)->format('Y-m-d') : null,
            //     'genap_pelaksanaan_mulai'     => $request->genap_pelaksanaan_mulai ? Carbon::createFromFormat('d/m/Y', $request->genap_pelaksanaan_mulai)->format('Y-m-d') : null,
            //     'genap_pelaksanaan_akhir'     => $request->genap_pelaksanaan_akhir ? Carbon::createFromFormat('d/m/Y', $request->genap_pelaksanaan_akhir)->format('Y-m-d') : null,
            //     'antara_pelaksanaan_mulai'    => $request->antara_pelaksanaan_mulai ? Carbon::createFromFormat('d/m/Y', $request->antara_pelaksanaan_mulai)->format('Y-m-d') : null,
            //     'antara_pelaksanaan_akhir'    => $request->antara_pelaksanaan_akhir ? Carbon::createFromFormat('d/m/Y', $request->antara_pelaksanaan_akhir)->format('Y-m-d') : null,
            // ]);
            
            $request->validate([
                'thn_akademik'   => 'required|string|max:5',
                'termin'         => 'required',
                'ganjil_mulai'   => 'required|date',
                'ganjil_akhir'   => 'required|date|after_or_equal:ganjil_mulai',
                'genap_mulai'    => 'required|date',
                'genap_akhir'    => 'required|date|after_or_equal:genap_mulai',
                'antara_mulai'   => 'nullable|date',
                'antara_akhir'   => 'nullable|date|after_or_equal:antara_mulai',
                'ganjil_pelaksanaan_mulai'   => 'required|date',
                'ganjil_pelaksanaan_akhir'   => 'required|date|after_or_equal:ganjil_pelaksanaan_mulai',
                'genap_pelaksanaan_mulai'    => 'required|date',
                'genap_pelaksanaan_akhir'    => 'required|date|after_or_equal:genap_pelaksanaan_mulai',
                'antara_pelaksanaan_mulai'   => 'nullable|date',
                'antara_pelaksanaan_akhir'   => 'nullable|date|after_or_equal:antara_pelaksanaan_mulai',
            ]);

            $data = $request->only([
                'thn_akademik',
                'termin',
                'ganjil_mulai',
                'ganjil_akhir',
                'genap_mulai',
                'genap_akhir',
                'antara_mulai',
                'antara_akhir',
                'ganjil_pelaksanaan_mulai',
                'ganjil_pelaksanaan_akhir',
                'genap_pelaksanaan_mulai',
                'genap_pelaksanaan_akhir',
                'antara_pelaksanaan_mulai',
                'antara_pelaksanaan_akhir',
            ]);

            $data['status'] = 1;
            $data['deleted_at'] = null;
            $data['deleted_by'] = null;

            $tahun_akademik = TahunAkademik::find($id_thn_akademik);

            if(!$tahun_akademik) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Tahun Akademik tidak ditemukan'
                ], 404);
            }

            $data = $tahun_akademik->update($data);


            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diperbarui',
                'data' => $data
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.',
            ], 404);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ], 500);
        }
    }

    public function tahunAkademik_delete(Request $request, int $id_thn_akademik) {
        try {
    
            $data = TahunAkademik::findOrFail($id_thn_akademik);

            $data->update([
                'status' => 0
            ]);
    
            return response()->json([
                'success' => true,
                'message' => 'Data tahun akademik berhasil dihapus.',
                'data' => $data
            ], 200);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.',
            ], 404);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ], 500);
        }
    }

    public function getTotalSksByMhsId(Request $request, int $mhs_id) {
        try {
            $listNilai = NilaiAkhirView::getNilaiAkhirByMhsId($mhs_id);
            $collectionListNilai = $listNilai;
            $countTotal = count($listNilai);

            if ($countTotal < 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan.'
                ]);
            }

            $countTotalSks = $collectionListNilai->sum(function ($matkul) {
                return $matkul['matakuliah']['sks'];
            });

            $groupedBySemester = $collectionListNilai->groupBy(function ($matkul) {
                return  $matkul['matakuliah']['semester'];
            });

            // ? jika terdapat query 's'
            if ($request->query('s')) {
                $semester = (string) $request->query('s');
                $filteredBySemester = self::getIPBySemester($semester, $groupedBySemester);

                return response()->json([
                    'status' => 'success',
                    'data' => $filteredBySemester
                ]);
            }

            // nilai keseluruhan
            $data = [
                'total_sks' => $countTotalSks
            ];

            $tempIPSemester = [];

            foreach ($groupedBySemester->toArray() as $index => $item) {
                $collectionSemester = collect($item);

                $countTotalSksSemester = $collectionSemester->sum(function ($matkul) {
                    return $matkul['matakuliah']['sks'];
                });

                $ipSemester = [
                    'semester' => $index,
                    'total_sks' => $countTotalSksSemester
                ];

                array_push($tempIPSemester, $ipSemester);
            }

            $data['ip_per_semester'] = $tempIPSemester;

            return response()->json([
                'status' => 'success',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
    
    private function getIPBySemester(string $semester, mixed $ipSemester) {
        $ipSemesterArr = $ipSemester->toArray();

        if (array_key_exists($semester, $ipSemesterArr)) {
            $tempData = $ipSemesterArr[$semester];

            // nilai keseluruhan dalam satu semester
            $collectionData = collect($tempData);
            $countTotalItem = $collectionData->count();
            $countTotalMutu = $collectionData->sum('mutu');
            $countTotalNilai = $collectionData->countBy('nilai');

            $countTotalSks = $collectionData->sum(function ($matkul) {
                return $matkul['matakuliah']['sks'];
            });

            $data = [
                'semester' => (int) $semester,
                'total_sks' => $countTotalSks,
                'total_ip' => ((float) $countTotalMutu / $countTotalItem),
                'total_nilai_a' => isset($countTotalNilai['A']) ? $countTotalNilai['A'] : 0,
                'total_nilai_b' => isset($countTotalNilai['B']) ? $countTotalNilai['B'] : 0,
                'total_nilai_c' => isset($countTotalNilai['C']) ? $countTotalNilai['C'] : 0,
                'total_nilai_d' => isset($countTotalNilai['D']) ? $countTotalNilai['D'] : 0,
                'total_nilai_e' => isset($countTotalNilai['E']) ? $countTotalNilai['E'] : 0,
            ];

            $tempMatkul = [];

            foreach ($tempData as $index => $item) {
                $newItem = [
                    'nm_mk' => trim($item['matakuliah']['nm_mk']),
                    'kd_mk' => trim($item['matakuliah']['kd_mk']),
                    'sks' => $item['matakuliah']['sks'],
                    'nilai' => $item['nilai'],
                    'mutu' => $item['mutu'],
                ];

                array_push($tempMatkul, $newItem);
            }

            $data['matakuliah'] = $tempMatkul;
        } else {
            return $data = [
                'semester' => (int) $semester,
                'total_sks' => 0,
                'total_ip' => 0,
                'total_nilai_a' => 0,
                'total_nilai_b' => 0,
                'total_nilai_c' => 0,
                'total_nilai_d' => 0,
                'total_nilai_e' => 0
            ];
        }

        return $data;
    }
}
