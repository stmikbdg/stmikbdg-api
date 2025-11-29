<?php

namespace App\Http\Controllers\Keuangan;

use App\Http\Controllers\Controller;
use App\Imports\Keuangan\MasterBeasiswaImportCollection;
use App\Models\Keuangan\MasterBeasiswa;
use App\Models\Keuangan\PenerimaBeasiswa;
use App\Models\Keuangan\TahunAkademik;
use App\Models\TahunAjaranView;
use App\Models\Users\Mahasiswa;
use App\Models\Users\MahasiswaView;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class MasterBeasiswaController extends Controller
{
    protected $master_beasiswa_model;
    protected $penerima_beasiswa_model;
    protected $tahun_akademik_model;
    protected $mahasiswa_model;
    protected $master_beasiswa_import_collection;
    protected $storage_path;

    public function __construct() {
        $this->master_beasiswa_model = new MasterBeasiswa();
        $this->penerima_beasiswa_model = new PenerimaBeasiswa();
        $this->tahun_akademik_model = new TahunAkademik();
        $this->mahasiswa_model = new Mahasiswa();
        $this->master_beasiswa_import_collection = new MasterBeasiswaImportCollection();
        $this->storage_path = 'keuangan/beasiswa/sk';
    }

    public function getAll_filters_tahun_ajaran (Request $request) {
        $data = TahunAjaranView::getTahunAjaranWithKRS()->filter(function ($item) {
            return $item['krs']->count() > 0;
        })
        ->map(function ($item) {
            return [
                'tahun_id' => $item['tahun_id'],
                'uraian' => $item['uraian']
            ];
        })
        ->sortByDesc('tahun_id')
        ->values();

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function getAll(Request $request) {
        $filters = $this->parseFilters($request->query('filters') ?? []);
        if(!isset($filters['by']) || !isset($filters['tahun_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'filters `by` harus diisi, [beasiswa/mahasiswa]. filters `tahun_id` harus diisi, angka'
            ], 403);
        }

        if(!in_array($filters['by'], ['beasiswa', 'mahasiswa']) || !is_numeric($filters['tahun_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'filters `by` harus diisi, [beasiswa|mahasiswa]. filters `tahun_id` harus diisi, angka'
            ], 403);
        }

        if($filters['by'] == 'mahasiswa') {
            $beasiswa = $this->penerima_beasiswa_model
                ->with('master_beasiswa')
                ->whereHas('master_beasiswa', function ($query) use ($filters) {
                    $query->where('id_thn_akademik', $filters['tahun_id'])->where('status', 1);
                })
                ->where('status', 1)
                ->get();

            $mahasiswa = $this->mahasiswa_model
                ->where('sts_mhs', 'A')
                
                // ->where('kd_kampus', 'A')
                ->whereNotNull('krs_id_last')
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

            $data = $beasiswa->map(function ($item) use ($mahasiswa) {
                $item['mahasiswa'] = $mahasiswa->where('mhs_id', $item['mhs_id'])->select(['nm_mhs', 'nim', 'jurusan'])->first();
                return $item;
            })
            ->filter(function ($item) {
                return $item['mahasiswa'] != null;
            })
            ->values();
            // ->filter(function ($item) {
            //     return $item['mahasiswa'] != null;
            // })->values(); jangan dulu di pake karena eror
        }else if($filters['by'] == 'beasiswa') {

            $mahasiswa = $this->mahasiswa_model
                ->where('sts_mhs', 'A')
                ->whereNotNull('krs_id_last')
                ->with('jurusan', 'krs')
                ->get();
            
             
            $beasiswa = $this->master_beasiswa_model
                ->whereHas('penerima_beasiswa', function ($query) use ($mahasiswa) {
                    $query
                        ->where('status', 1)
                        ->whereIn('mhs_id', $mahasiswa->pluck('mhs_id')->toArray());
                })
                ->with([
                    'penerima_beasiswa' => function ($query) use ($mahasiswa) {
                        $query
                            ->where('status', 1)
                            ->whereIn('mhs_id', $mahasiswa->pluck('mhs_id')->toArray());
                    }
                ])
                ->where('id_thn_akademik', $filters['tahun_id'])
                ->where('status', 1)
                ->get();

            foreach ($beasiswa as $bws) {
                foreach ($bws->penerima_beasiswa as $penerima) {
                    $penerima->mahasiswa = $mahasiswa->where('mhs_id', $penerima['mhs_id'])->select(['nm_mhs', 'nim', 'jurusan'])->first();
                }
            }

            $data = $beasiswa;
        }

        return response()->json([
            'success' => true,
            'data' => $data ?? []
        ]);
    }

    public function create(Request $request) {
        try {

            $skipped = [];

            $request->validate([
                'nama_beasiswa' => 'required|string|max:255',
                'id_thn_akademik' => 'required|integer',
                'file_sk' => 'required|file|mimes:pdf,doc,docx|max:2048',
                'file_excel' => 'nullable|file|mimes:xlsx,xls',
                'mhs_id' => 'nullable',
            ]);


            $tahun_akademik = TahunAjaranView::getTahunAjaranWithKRS()
                ->filter(function ($item) {
                    return $item['krs']->count() > 0;
                })
                ->sortByDesc('tahun_id')
                ->values()
                ->where('tahun_id', $request->id_thn_akademik)
                ->first();

            if(!$tahun_akademik) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tahun akademik tidak ditemukan.',
                ], 404);
            }

            
            if($request->hasFile('file_sk')) {
                $now = Carbon::now();
                $fileName = 'SK_BEASISWA_'.str_replace(' / ', '_', $tahun_akademik['uraian']).'_'.str_replace(' ', '_', $request->nama_beasiswa).'_'.$now->format('Ymd_His').'.pdf';

                $response_file = $this->uploadFile('keuangan/beasiswa/sk', $fileName, $request->file('file_sk'));

                if(!$response_file['success']) {
                    return response()->json([
                        'success' => false,
                        'message' => $response_file['message'],
                        'error' => $response_file['error']
                    ]);
                }

                $url = $response_file['data']['url'];
            }

            if(!$request->hasFile('file_excel')) { // Input Manual
                if(!$request->filled('mhs_id')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda belum memilih mahasiswa.'
                    ], 400);
                }

                $hasActive = $this->penerima_beasiswa_model
                    ->where('mhs_id', $request->mhs_id)
                    ->whereHas('master_beasiswa', function ($query) use ($request, $tahun_akademik) {
                        $query->where('id_thn_akademik', $tahun_akademik['tahun_id'])
                            ->where('status', 1);
                    })
                    ->where('status', 1)
                    ->exists();
                
                if($hasActive) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Mahasiswa ini sudah memiliki beasiswa aktif di tahun akademik dan semester ini.'
                    ], 400);
                }
            }else{ // Input Excel

                $mahasiswa = $this->mahasiswa_model
                    ->where('sts_mhs', 'A')
                    // ->where('kd_kampus', 'A')
                    ->whereNotNull('krs_id_last')
                    ->with('jurusan')
                    ->get();

                $master_beasiswa_import_collection = $this->master_beasiswa_import_collection;

                Excel::import($master_beasiswa_import_collection, $request->file('file_excel'));

                $data_excel_nims = $master_beasiswa_import_collection->rows->map(function ($row) {
                    return (string) $row['nim'];
                });

                if ($data_excel_nims->isEmpty()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda belum memilih mahasiswa ke dalam file excel tersebut.'
                    ], 400);
                }

                // NIM yang ada di tabel mahasiswa
                $nim_mahasiswa = $mahasiswa->pluck('nim');

                // NIM valid (yang ada di mahasiswa)
                $nim_valid = $data_excel_nims->intersect($nim_mahasiswa)->values();

                // NIM tidak valid (tidak ada di mahasiswa)
                $nim_tidak_ada = $data_excel_nims->diff($nim_mahasiswa)->values();

                // Ambil data mahasiswa valid
                $mhs_exists = $mahasiswa
                    ->whereIn('nim', $nim_valid->toArray())
                    ->values();
                
                if ($mhs_exists->isEmpty()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Mahasiswa tersebut tidak ditemukan.'
                    ], 400);
                }

                $success = [];
                $failed = [];

                foreach ($mhs_exists as $mhs) {
                    $hasActive = $this->penerima_beasiswa_model
                        ->where('mhs_id', $mhs->mhs_id)
                        ->whereHas('master_beasiswa', function ($query) use ($request, $tahun_akademik) {
                            $query->where('id_thn_akademik', $tahun_akademik['tahun_id'])
                                ->where('status', 1);
                        })
                        ->where('status', 1)
                        ->exists();

                    if (!$hasActive) {
                        $success[] = $mhs->mhs_id;
                    } else {
                        $failed[] = $mhs->nim;
                    }
                }

                if (count($success) == 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Semua mahasiswa di file sudah memiliki beasiswa aktif di tahun akademik dan semester ini.',
                        'failed_nim' => $failed
                    ], 400);
                }
            }

            $beasiswa = $this->master_beasiswa_model->create([
                'nama_beasiswa' => $request->nama_beasiswa,
                'id_thn_akademik' => $tahun_akademik['tahun_id'],
                'file_sk' => $url ?? null,
                'status' => 1,
            ]);

            if(!$request->hasFile('file_excel')) { // Input Manual
                if(!$request->filled('mhs_id')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda belum memilih mahasiswa.'
                    ], 400);
                }

                $beasiswa->penerima_beasiswa()->create([
                    'mhs_id' => $request->mhs_id,
                    'status' => 1
                ]);
            } else { // Input Excel

                $payload = collect($success)->map(function ($mhs_id) use ($beasiswa) {
                    return [
                        'mhs_id' => $mhs_id,
                        'status' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'id_beasiswa' => $beasiswa->id_beasiswa
                    ];
                });

                $beasiswa->penerima_beasiswa()->insert($payload->toArray());
            }

            return response()->json([
                'success' => true,
                'message' => 'Beasiswa berhasil ditambahkan.',
                'data' => [
                    'beasiswa' => $beasiswa,
                    'jumlah_berhasil' => count($success ?? []),
                    'jumlah_gagal' => count($nim_tidak_ada ?? []),
                    'nim_gagal' => $nim_tidak_ada ?? []
                ]
            ]);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }

    public function update_by_id(Request $request, int $id_beasiswa) {
        try {

            $skipped = [];

            $beasiswa = $this->master_beasiswa_model->with('penerima_beasiswa')->find($id_beasiswa);

            if (!$beasiswa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data beasiswa tidak ditemukan'
                ], 404);
            }

            $request->validate([
                'nama_beasiswa' => 'required|string',
                'id_thn_akademik' => 'required|integer',
                'file_sk' => 'nullable|file|mimes:pdf|max:2048',
                'file_excel' => 'nullable|file|mimes:xlsx,xls',
            ]);

            $tahun_akademik = TahunAjaranView::getTahunAjaranWithKRS()
                ->filter(function ($item) {
                    return $item['krs']->count() > 0;
                })
                ->values()
                ->where('tahun_id', $request->id_thn_akademik)
                ->first();

            if(!$tahun_akademik) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tahun akademik tidak ditemukan.',
                ], 404);
            }

            $beasiswa_payload = [
                'nama_beasiswa' => $request->nama_beasiswa,
                'id_thn_akademik' => $tahun_akademik->tahun_id,
            ];

            if($request->hasFile('file_sk')) {
                $file = $request->file('file_sk');
                $now = Carbon::now();
                $fileName = 'SK_BEASISWA_'.str_replace(' / ', '_', $tahun_akademik['uraian']).'_'.str_replace(' ', '_', $request->nama_beasiswa).'_'.$now->format('Ymd_His').'.pdf';

                $response_file = $this->uploadFile($this->storage_path, $fileName, $file);

                if(!$response_file['success']) {
                    return response()->json([
                        'success' => false,
                        'message' => $response_file['message'],
                        'error' => $response_file['error']
                    ]);
                }

                $beasiswa_payload['file_sk'] = $response_file['data']['url'];
            }

            $beasiswa->update($beasiswa_payload);

            $success = [];
            $failed = [];
            $nim_tidak_ada = collect();

            if($request->hasFile('file_excel')) {
                // Semua penerima lama jadi nonaktif dulu
                $this->penerima_beasiswa_model->where('id_beasiswa', $id_beasiswa)->update([
                    'status' => 0
                ]);

                $mahasiswa = $this->mahasiswa_model
                    ->where('sts_mhs', 'A')
                    ->whereNotNull('krs_id_last')
                    ->with('jurusan')
                    ->get();

                Excel::import($this->master_beasiswa_import_collection, $request->file('file_excel'));

                $data_excel_nims = $this->master_beasiswa_import_collection->rows->map(function ($row) {
                    return (string) $row['nim'];
                });

                if($data_excel_nims->isEmpty()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda belum memilih mahasiswa ke dalam file excel tersebut.'
                    ], 400);
                }

                // Cek nim valid dan nim tidak ada
                $nim_mahasiswa = $mahasiswa->pluck('nim');
                $nim_valid = $data_excel_nims->intersect($nim_mahasiswa)->values();
                $nim_tidak_ada = $data_excel_nims->diff($nim_mahasiswa)->values();

                // Ambil data mahasiswa valid
                $mhs_exists = $mahasiswa->whereIn('nim', $nim_valid->toArray())->values();

                if ($mhs_exists->isEmpty()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Mahasiswa tersebut tidak ditemukan.'
                    ], 400);
                }

                foreach ($mhs_exists as $mhs) {
                    $hasActiveInSamePeriod = $this->penerima_beasiswa_model
                        ->where('mhs_id', $mhs->mhs_id)
                        ->where('status', 1)
                        ->where('id_beasiswa', '!=', $id_beasiswa)
                        ->whereHas('master_beasiswa', function ($query) use ($request) {
                            $query->where('id_thn_akademik', $request->id_thn_akademik);
                        })
                        ->exists();
                    
                    if ($hasActiveInSamePeriod) {
                        $skipped[] = $mhs->nim;
                        continue;
                    }

                    $wasInactiveInSamePeriod = $this->penerima_beasiswa_model
                        ->where('mhs_id', $mhs->mhs_id)
                        ->where('status', 0)
                        ->whereHas('master_beasiswa', function ($query) use ($request) {
                            $query->where('id_thn_akademik', $request->id_thn_akademik);
                        })
                        ->exists();

                    if (!$hasActiveInSamePeriod || $wasInactiveInSamePeriod) {
                        $this->penerima_beasiswa_model->updateOrCreate(
                            [
                                'id_beasiswa' => $id_beasiswa,
                                'mhs_id' => $mhs->mhs_id,
                            ],
                            [
                                'status' => 1,
                            ]
                        );
                        $success[] = $mhs->mhs_id;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Beasiswa berhasil diperbarui.',
                'data' => [
                    'beasiswa' => $beasiswa,
                    'jumlah_berhasil' => count($success),
                    'jumlah_gagal' => count($nim_tidak_ada),
                    'nim_gagal' => $nim_tidak_ada,
                    'skipped' => $skipped
                ]
            ]);


        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error->getTraceAsString()
            ]);
        }
    }

    public function delete_by_id(Request $request, int $id_beasiswa) {
        try {
            $data = $this->master_beasiswa_model->with('penerima_beasiswa')->find($id_beasiswa);

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan'
                ], 404);
            }
            
            $mahasiswa = $data['penerima_beasiswa'];

            $data->update([
                'status' => 0
            ]);

            $data->penerima_beasiswa()->update([
                'status' => 0
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Beasiswa dan data penerima berhasil dihapus!',
                'data' => [
                    'master_beasiswa' => $data,
                    'jumlah_mhs' => $mahasiswa->count()
                ]
            ], 200);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }

    public function delete_by_id_penerima(Request $request, int $id_penerima) {
        try {
            $data = $this->penerima_beasiswa_model->find($id_penerima);

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan'
                ], 404);
            }

            $data->update([
                'status' => 0
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Data penerima berhasil dihapus!',
                'data' => $data
            ], 200);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }

    public function delete_by_mhs_id(Request $request, int $mhs_id) {
        try {
            $exist = $this->penerima_beasiswa_model->where('mhs_id', $mhs_id)->get();

            if ($exist->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data penerima beasiswa tidak ditemukan'
                ], 404);
            }

            $data = $this->penerima_beasiswa_model->where('mhs_id', $mhs_id)->update([
                'status' => 0
            ]);

            return response()->json([
                'success' => true,
                'message' => 'data penerima berhasil dihapus!'
            ], 200);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }
}
