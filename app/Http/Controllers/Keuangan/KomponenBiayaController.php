<?php

namespace App\Http\Controllers\Keuangan;

use App\Http\Controllers\Controller;
use App\Models\Keuangan\BiayaPerMahasiswa;
use App\Models\Keuangan\KomponenBiaya;
use App\Models\Keuangan\KomponenFixed;
use App\Models\Keuangan\ManajemenBiaya;
use App\Models\Keuangan\MasterKomponenBiaya;
use App\Models\Keuangan\PenerimaBeasiswa;
use App\Models\TahunAjaranView;
use App\Models\Users\Mahasiswa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KomponenBiayaController extends Controller
{
    protected $model;

    public function __construct() {
        $this->model = new KomponenBiaya();
    }

    public function getAll(Request $request) {

        $isFixed = $request->query('is_fixed');

        if($isFixed) {
            $data = KomponenFixed::all();
        }else{
            $data = $this->model->get();
        }


        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function getAll_by_tahun_angkatan(Request $request) {
        
        $tahun_angkatan = Mahasiswa::getAllUniqueByColumns(['masuk_tahun'])
            ->pluck('masuk_tahun')
            ->sortDesc()
            ->values();

        // Get all data for those tahun_angkatan in a single query
        $allData = $this->model
            ->whereIn('tahun_angkatan', $tahun_angkatan)
            ->get()
            ->map(function ($item) {
                $item['is_fixed'] = false;
                return $item;
            })
            ->groupBy('tahun_angkatan');

        $komponen_fixed = KomponenFixed::all()->map(function ($item) {
            $item['is_fixed'] = true;
        
            return $item;
        });

        // Map and structure the data, but only if model data exists
        $parsedData = $tahun_angkatan->map(function ($item) use ($allData, $komponen_fixed) {
            $modelData = $allData->get($item, collect());

            if ($modelData->isEmpty()) {
                return null; // exclude this entry
            }

            return [
                'tahun_angkatan' => $item,
                'data' => array_merge($komponen_fixed->toArray(), $modelData->toArray())
            ];
        })->filter()->values(); // remove nulls and reindex

        return response()->json([
            'success' => true,
            'data' => $parsedData
        ], 200);
    }

    public function create_by_tahun_angkatan(Request $request) {
        DB::beginTransaction();


        try {
            $validated = $request->validate([
                'tahun_ajaran' => 'required',
                'tahun_angkatan' => 'required',
                'id_nama_komponen' => 'nullable|array',
                'nama_komponen' => 'required|array',
                'kewajiban' => 'required|array',
                'ket' => 'nullable|array',
            ]);


            // Siapkan input komponen
            $id_nama_komponen = $validated['id_nama_komponen'] ?? [];
            $nama_komponen = $validated['nama_komponen'];
            $kewajiban = array_map(function ($value) {
                return (int) str_replace('.', '', $value);
            }, $validated['kewajiban']);
            $ket = $validated['ket'] ?? [];


            foreach ($nama_komponen as $i => $komponen) {
                if (!isset($ket[$i])) {
                    $ket[$i] = '';
                }
            }


            // Finalisasi komponen
            $final_komponen = [];
            $used_ids = [];


            foreach ($nama_komponen as $i => $komponen) {
                $id_fixed = $id_nama_komponen[$i] ?? null;


                if (empty($id_fixed)) {
                    do {
                        $id_fixed = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
                    } while (in_array($id_fixed, $used_ids));
                }


                $used_ids[] = $id_fixed;


                $final_komponen[] = [
                    'tahun_angkatan' => (int) $validated['tahun_angkatan'],
                    'id_nama_komponen' => $id_fixed,
                    'nama_komponen' => $komponen,
                    'kewajiban' => $kewajiban[$i],
                    'ket' => $ket[$i],
                    'status' => true,
                    'tahun_id' => (int) $validated['tahun_ajaran'],
                ];
            }


            // Simpan ke KomponenBiaya dan ambil ID-nya
            $saved_komponen = [];


            foreach ($final_komponen as $komponen) {
                $komponenModel = KomponenBiaya::create($komponen);
                $saved_komponen[] = $komponenModel;
            }


            // Ambil mahasiswa dari service
            // $mahasiswaList = $this->mahasiswaService->getMahasiswa();
            $mahasiswa = Mahasiswa::getAllMahasiswa([])->toArray();
           
            // Ambil id tahun ajaran yang dipilih dari validated
            $tahunId = (int) $validated['tahun_ajaran'];

            // $penerima_beasiswa = $this->beasiswaService->getBeasiswaByMahasiswa($tahunId);
            $beasiswa = PenerimaBeasiswa::with('master_beasiswa')
                ->whereHas('master_beasiswa', function ($query) use ($tahunId) {
                    $query->where('id_thn_akademik', $tahunId)->where('status', 1);
                })
                ->where('status', 1)
                ->get();

            $mahasiswa = Mahasiswa::where('sts_mhs', 'A')
                
                // ->where('kd_kampus', 'A')
                // ->whereNotNull('krs_id_last')
                // ->whereIn('mhs_id', $beasiswa->pluck('mhs_id')->toArray())
                // ->whereHas('krs.krsMatkul.kelasKuliahJoin', function ($query) use ($filters) {
                //     $query->where('tahun_id', $filters['tahun_id']);
                // })

                ->with('jurusan', 'krs.krsMatkul.kelasKuliahJoin')
                // ->pluck(['mhs_id', 'nm_mhs', 'nim', 'jurusan']);
                ->get();

            // dd($mahasiswa);
            // dd($mahasiswa->toArray());

            // return response()->json([
            //     'data' => $mahasiswa
            // ]);

            $penerima_beasiswa = $beasiswa->map(function ($item) use ($mahasiswa) {
                $item['mahasiswa'] = $mahasiswa->where('mhs_id', $item['mhs_id'])->select(['nm_mhs', 'nim', 'jurusan'])->first();
                return $item;
            })
            ->filter(function ($item) {
                return $item['mahasiswa'] != null;
            })
            ->values();

            // Ambil detail tahun ajaran terpilih
            $tahunAjaranTerpilih = TahunAjaranView::getTahunAjaranWithKRS()
                ->filter(function ($item) {
                    // if ($item['krs']->count() > 0) {
                        return $item;
                    // }
                })
                ->flatten()
                ->firstWhere('tahun_id', $tahunId);

            if (!$tahunAjaranTerpilih) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tahun ajaran tidak ditemukan.'
                ], 404);
            }

            // Filter mahasiswa sesuai kriteria
            $filteredMahasiswa = $mahasiswa
                ->where('masuk_tahun', (int) $validated['tahun_angkatan'])
                ->filter(function ($mhs) use ($tahunAjaranTerpilih) {
                    return trim($mhs['jur_id']) == trim($tahunAjaranTerpilih['jur_id'])
                        && trim($mhs['kd_kampus']) == trim($tahunAjaranTerpilih['kd_kampus'])
                        && trim($mhs['jns_mhs']) == trim($tahunAjaranTerpilih['jns_mhs']);
                });

            foreach ($filteredMahasiswa as $mhs) {
                $exists = ManajemenBiaya::where('mhs_id', $mhs['mhs_id'])->exists();
                if ($exists) continue;

                // Cek apakah mahasiswa ini mendapat beasiswa di tahun ajaran aktif
                $penerima = collect($penerima_beasiswa)->first(function ($item) use ($mhs, $tahunId) {
                    return $item['mhs_id'] == $mhs['mhs_id']
                        && isset($item['master_beasiswa']['id_thn_akademik'])
                        && $item['master_beasiswa']['id_thn_akademik'] == $tahunId;
                });

                if ($penerima) {
                    $beasiswa = $penerima['master_beasiswa'];
                    $statusMahasiswa = $beasiswa['nama_beasiswa'];
                } else {
                    $statusMahasiswa = 'Non Beasiswa';
                }

                $manajemen = ManajemenBiaya::create([
                    'mhs_id' => $mhs['mhs_id'],
                    'status_mahasiswa' => $statusMahasiswa,
                    'status' => 1
                ]);

                foreach ($saved_komponen as $komponen) {
                    if (in_array($komponen->id_nama_komponen, [5, 6, 11])) {
                        continue; // Lewati komponen 5, 6, dan 11
                    }


                    $biaya = BiayaPerMahasiswa::create([
                        'id_manajemen_biaya' => $manajemen->id_manajemen_biaya,
                        'id_komponen_biaya' => $komponen->id_komponen,
                        'potongan_persen' => 0,
                        'potongan_beasiswa' => 0,
                        'jumlah' => $komponen->kewajiban,
                        'sisa' => $komponen->kewajiban,
                        'status' => 1,
                        'ket' => $komponen->ket,
                        'tahun_id' => $tahunId
                    ]);
                }
            }




            DB::commit();


            return response()->json([
                'success' => true,
                'message' => 'Biaya dan kewajiban mahasiswa berhasil disimpan untuk tahun angkatan ini.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e->getTraceAsString()
            ], 500);
        }
    }
    
    // public function create_by_tahun_angkatan(Request $request) {
    //     try {
    //         $validated = $request->validate([
    //             'tahun_angkatan' => 'required',
    //             'jenis_kelas' => 'required',
    //             'id_nama_komponen' => 'nullable|array',
    //             'nama_komponen' => 'required|array',
    //             'kewajiban' => 'required|array',
    //             'ket' => 'nullable|array',
    //             'status' => 'required|boolean'
    //         ]);

    //         $exist = $this->model
    //             ->where('tahun_angkatan', $validated['tahun_angkatan'])
    //             ->where('jenis_kelas', $validated['jenis_kelas'])
    //             ->exists();

    //         if($exist) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Data untuk tahun angkatan ini sudah ada.'
    //             ], 403);
    //         }

    //         $id_nama_komponen = collect($validated['id_nama_komponen'] ?? []);
    //         $nama_komponen = collect($validated['nama_komponen']);
    //         // $kewajiban = collect($validated['kewajiban']);
    //         $kewajiban = array_map(function ($value) {
    //             return (int) str_replace('.', '', $value);
    //         }, $validated['kewajiban']);
    //         $ket = collect($validated['ket'] ?? []);

    //         foreach ($nama_komponen as $i => $komponen) {
    //             if (!isset($ket[$i])) {
    //                 $ket[$i] ='';
    //             }
    //         }

    //                     // Finalisasi komponen
    //         $final_komponen = [];
    //         $used_ids = [];

    //         foreach ($nama_komponen as $i => $komponen) {
    //             $id_fixed = $id_nama_komponen[$i] ?? null;

    //             if (empty($id_fixed)) {
    //                 do {
    //                     $id_fixed = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
    //                 } while (in_array($id_fixed, $used_ids));
    //             }

    //             $used_ids[] = $id_fixed;

    //             $final_komponen[] = [
    //                 'tahun_angkatan' => (int) $validated['tahun_angkatan'],
    //                 'jenis_kelas' => $validated['jenis_kelas'],
    //                 'id_nama_komponen' => $id_fixed,
    //                 'nama_komponen' => $komponen,
    //                 'kewajiban' => $kewajiban[$i],
    //                 'ket' => $ket[$i],
    //                 'status' => true,
    //             ];
    //         }

    //         // Simpan ke KomponenBiaya dan ambil ID-nya
    //         $saved_komponen = MasterKomponenBiaya::insert($komponen);

    //         $mahasiswa = Mahasiswa::getAllMahasiswa([])->toArray();

            

    //         if ($nama_komponen->count() !== $kewajiban->count()) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Jumlah nama komponen dan kewajiban tidak sama.'
    //             ], 422);
    //         }

    //         $dataToInsert = $nama_komponen->map(function ($komponen, $key) use ($validated, $id_nama_komponen, $kewajiban, $ket) {
    //             return [
    //                 'id_nama_komponen' => $id_nama_komponen->get($key) ?? str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT),
    //                 'tahun_angkatan' => (int) $validated['tahun_angkatan'],
    //                 'nama_komponen' => (string) $komponen,
    //                 'kewajiban' => (int) str_replace('.', '', $kewajiban->get($key, 0)),
    //                 'ket' => $ket->get($key),
    //                 'status' => 1,
    //                 'created_at' => now(),
    //                 'updated_at' => now()
    //             ];
    //         });

    //         $data = $this->model->insert($dataToInsert->toArray());

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Komponen biaya berhasil ditambahkan!',
    //             'data' => $data
    //         ]);

    //     } catch (\Exception $error) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $error->getMessage(),
    //             'error' => $error
    //         ]);
    //     }
    // }

    public function update_by_tahun_angkatan(Request $request) {
        try {
            $validated = $request->validate([
                'tahun_angkatan' => 'required|integer',
                'id_komponen' => 'nullable|array',
                'id_nama_komponen' => 'nullable|array',
                'nama_komponen' => 'required|array',
                'kewajiban' => 'required|array',
                'ket' => 'nullable|array',
            ]);

            $tahunAngkatan = $validated['tahun_angkatan'];
            $idKomponen = collect($validated['id_komponen'] ?? []);
            $idNamaKomponen = collect($validated['id_nama_komponen'] ?? []);
            $namaKomponen = collect($validated['nama_komponen']);
            $kewajiban = collect($validated['kewajiban']);
            $keterangan = collect($validated['ket'] ?? []);

            // Validate data length consistency
            if (
                $namaKomponen->count() !== $kewajiban->count()
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jumlah data tidak konsisten.'
                ], 422);
            }

            $namaKomponen->each(function ($nama, $key) use (
                $tahunAngkatan, $idKomponen, $idNamaKomponen, $kewajiban, $keterangan
            ) {
                $komponenId = $idKomponen->get($key);
                $komponenFixedId = $idNamaKomponen->get($key) ?? str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
                $kewajibanBersih = (int) str_replace(['.', ','], '', $kewajiban->get($key));
                $ket = $keterangan->get($key);

                $data = [
                    'tahun_angkatan' => $tahunAngkatan,
                    'id_nama_komponen' => $komponenFixedId,
                    'nama_komponen' => $nama,
                    'kewajiban' => $kewajibanBersih,
                    'ket' => $ket,
                    'status' => 1,
                ];

                if ($komponenId) {
                    $this->model->where('id_komponen', $komponenId)->update($data);
                } else {
                    $this->model->create($data);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Data komponen biaya berhasil diupdate!'
            ]);


        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }
}
