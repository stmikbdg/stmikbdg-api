<?php

namespace App\Http\Controllers\SIKPS;

use App\Http\Controllers\Controller;
use App\Models\SIKPS\DataKpSkripsi;
use App\Models\SIKPS\MasterBimbingan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class MasterBimbinganController extends Controller
{

    protected $model;

    public function __construct() {
        $this->model = new MasterBimbingan();
    }

    public function getAll_admin(Request $request) {
        $data = $this->model->with('data_kp_skripsi')->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function getAll_dospem(Request $request) {

        Carbon::setLocale('id');

        $user = $this->getUserAuth();

        $filters = $this->parseFilters($request->query('filters') ?? []);

        $data = $this->model->with('data_kp_skripsi')
            ->whereHas('data_kp_skripsi', function ($query) use ($user) {
                $query->where('pembimbing', 'like', '%'.$user->nama.'%');
            })
            ->get()
            ->map(function ($item) {
                $item['tanggal'] = Carbon::parse($item->created_at)->translatedFormat('d F Y'); 

                return $item;
            });

        return response()->json([
            'success' => true,
            'filters' => $filters,
            'data' => $data
        ], 200);
    }

    public function getAll_mahasiswa(Request $request) {
        $user = $this->getUserAuth();

        Carbon::setLocale('id');

        $filters = $this->parseFilters($request->query('filters') ?? []);

        $data = $this->model->with('data_kp_skripsi')
            ->whereHas('data_kp_skripsi', function ($query) use ($user, $filters) {
                $query->where('nim', 'like', '%'.$user->nim.'%');
                
                if($filters) {
                    if(isset($filters['jenis_laporan'])) {
                        $query->whereIn('jenis_laporan', array_map( function ($item) {
                            if($item == 'kp' || $item == 'Kerja Praktek') {
                                return 'Kerja Praktek';
                            }

                            if($item == 'skripsi' || $item == 'Skripsi') {
                                return 'Skripsi';
                            }
                        }, (array) $filters['jenis_laporan']));
                    }
                }
            })
            ->get()
            ->map(function ($item) {
                $item['tanggal'] = Carbon::parse($item->created_at)->translatedFormat('d F Y'); 

                return $item;
            });

        return response()->json([
            'success' => true,
            'filters' => $filters,
            'data' => $data
        ], 200);
    }

    public function create_admin(Request $request) {
        try {
            $request->validate([
                'bab' => 'required|integer',
                'tanggal' => 'required|date',
                'jenis_dokumen' => 'required|string|max:10|in:file,link',
                'dokumen' => 'nullable|string',
                'file' => 'nullable|file|mimes:pdf,doc,docx',
                'catatan' => 'nullable|string',
                'status' => 'required|string|max:10',
                'is_arsip' => 'required|string|in:true,false',
                'data_kp_skripsi_id' => 'required|integer',
                'progress' => 'decimal:0|required',
            ]);

            // Cari data kp skripsi
            $data_kp_skripsi = DataKpSkripsi::find($request->data_kp_skripsi_id);

            if(!$data_kp_skripsi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data KP Skripsi tidak ditemukan'
                ], 404);
            }

            // Cari data bimbingan yg ga sama
            $master_bimbingan = $this->model->where('data_kp_skripsi_id', $request->data_kp_skripsi_id)->where('bab', $request->bab)->get();

            if(!$master_bimbingan->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data bimbingan sudah ada'
                ], 400);
            }

            $body = $request->only((new MasterBimbingan)->getFillable());

            if($body['jenis_dokumen'] == 'file') {
                if($request->hasFile('file')) {
                    $file = $request->file('file');
                    
                    $filename = 'Laporan_'
                        .str_replace(' ', '_', $data_kp_skripsi->jenis_laporan)
                        .'_-_BAB_'
                        .$body['bab']
                        .'_-_'
                        .str_replace(' ', '_', $data_kp_skripsi->nama_mahasiswa)
                        .'_('
                        .$data_kp_skripsi->nim
                        .').'
                        .$file->getClientOriginalExtension();

                    $path = Storage::disk('r2')->putFileAs('sikps/master-bimbingan', $file, $filename);

                    $url = Storage::disk('r2')->url($path);

                    $body['dokumen'] = $url;
                }
            }


            $data = MasterBimbingan::create($body);

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);

        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ], 500);
        }
    }

    public function create_mahasiswa(Request $request) {
        try {

            $request->validate([
                'jenis_dokumen' => 'nullable|string|max:10|in:file,link',
                'jenis_bimbingan' => 'required|string|in:laporan,project sistem',
                'dokumen' => 'nullable|string',
                'file' => 'nullable|file|mimes:pdf,doc,docx',
                'data_kp_skripsi_id' => 'required|integer'
            ]);

            $user = $this->getUserAuth();

            // Cari data kp skripsi
            $data_kp_skripsi = DataKpSkripsi::where('id', $request->data_kp_skripsi_id)->where('nim', $user->nim)->first();

            if(!$data_kp_skripsi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data KP Skripsi tidak ditemukan'
                ], 404);
            }

            // Cek jika jenis bimbingan untuk laporan terdapat progress yg belum 100%
            if($request->jenis_bimbingan == 'laporan') {
                $master_bimbingan = $this->model->where('data_kp_skripsi_id', $request->data_kp_skripsi_id)->where('jenis_bimbingan', 'laporan')->first();

                if($master_bimbingan && $master_bimbingan->progress < 100) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Progress laporan anda sebelumnya belum 100%'
                    ], 400);
                }
            }

            $body = $request->only((new MasterBimbingan)->getFillable());

            if($request->jenis_bimbingan == 'laporan') {
                if($body['jenis_dokumen'] == 'file') {
                    if($request->hasFile('file')) {
                        $file = $request->file('file');
                        
                        $filename = 'Laporan_'
                            .str_replace(' ', '_', $data_kp_skripsi->jenis_laporan)
                            .'_-_'
                            .str_replace(' ', '_', $data_kp_skripsi->nama_mahasiswa)
                            .'_('
                            .$data_kp_skripsi->nim
                            .').'
                            .$file->getClientOriginalExtension();

                        $path = Storage::disk('r2')->putFileAs('sikps/master-bimbingan', $file, $filename);

                        $url = Storage::disk('r2')->url($path);

                        $body['dokumen'] = $url;
                    }
                }else{
                    if($request->has('dokumen')) {
                        $body['dokumen'] = $request->dokumen;
                    }
                }
            }else{
                $body['dokumen'] = isset($request->dokumen) ? $request->dokumen : null;
            }

            $body['progress'] = 0;
            $body['status'] = 'review';

            $data = MasterBimbingan::create($body);

            return response()->json([
                'success' => true,
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

    public function update_admin(Request $request, int $id) {
        try {
            $data = $this->model->find($id)->with('data_kp_skripsi');

            if(!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan'
                ], 404);
            }

            $request->validate([
                'bab' => 'required|integer',
                'tanggal' => 'required|date',
                'jenis_dokumen' => 'required|string|max:10|in:file,link',
                'dokumen' => 'nullable|string',
                'file' => 'nullable|file|mimes:pdf,doc,docx',
                'catatan' => 'nullable|string',
                'status' => 'required|string|max:10',
                'is_arsip' => 'required|string|in:true,false',
                'data_kp_skripsi_id' => 'required|integer',
                'progress' => 'required|numeric|between:0,100',
            ]);

            // Cari data kp skripsi
            $data_kp_skripsi = DataKpSkripsi::find($request->data_kp_skripsi_id);

            if(!$data_kp_skripsi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data KP Skripsi tidak ditemukan'
                ], 404);
            }

            // Cari data bimbingan yg ga sama
            $master_bimbingan = $this->model->where('data_kp_skripsi_id', (int) $request->data_kp_skripsi_id)->where('bab', (int) $request->bab)->get();

            if(!$master_bimbingan->isEmpty()) {
                if($master_bimbingan->first()->id != $id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Data bimbingan sudah ada' 
                    ]);
                }
            }

            $body = $request->only((new MasterBimbingan)->getFillable());

            if($body['jenis_dokumen'] == 'file') {
                if($request->hasFile('file')) {
                    $file = $request->file('file');
                    
                    $filename = 'Laporan_'
                        .str_replace(' ', '_', $data_kp_skripsi->jenis_laporan)
                        .'_-_BAB_'
                        .$body['bab']
                        .'_-_'
                        .str_replace(' ', '_', $data_kp_skripsi->nama_mahasiswa)
                        .'_('
                        .$data_kp_skripsi->nim
                        .').'
                        .$file->getClientOriginalExtension();

                    $path = Storage::disk('r2')->putFileAs('sikps/master-bimbingan', $file, $filename);

                    $url = Storage::disk('r2')->url($path);

                    $body['dokumen'] = $url;
                }
            }

            $data->update($body);

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);

        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ], 500);
        }
    }

    public function update_mahasiswa(Request $request, int $id) {
        try {
            $body = $request->validate([
                'bab' => 'required|integer',
                'tanggal' => 'required|date',
                'jenis_dokumen' => 'required|string|max:10',
                'dokumen' => 'required|string'
            ]);

            return response()->json([
                'body' => $body
            ]);

            $data = $this->model->find($id);

            if(!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan'
                ], 404);
            }

            // Cari data kp skripsi
            $data_kp_skripsi = DataKpSkripsi::find($request->data_kp_skripsi_id);

            if(!$data_kp_skripsi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data KP Skripsi tidak ditemukan'
                ], 404);
            }

            // Cari data bimbingan yg ga sama
            $master_bimbingan = $this->model->where('data_kp_skripsi_id', $request->data_kp_skripsi_id)->where('bab', $request->bab)->get();

            if(!$master_bimbingan->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data bimbingan sudah ada'
                ], 400);
            }

            // $body = $request->only((new MasterBimbingan)->getFillable());

            $data->update($body);

            return response()->json([
                'success' => true,
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

    public function review_dospem(Request $request, int $id) {
        $data = $this->model->with('data_kp_skripsi')->find($id);

        if(!$data) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function feedback_dospem(Request $request, int $id) {
        try {

            $data = $this->model->with('data_kp_skripsi')->find($id);

            if(!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan'
                ], 404);
            }

            // return response()->json([
            //     'data' => $data 
            // ]);

            $body = $request->validate([
                'jenis_dokumen' => 'required|string|max:10|in:file,link',
                'dokumen' => 'nullable|string',
                'file' => 'nullable|file|mimes:pdf,doc,docx',
                'catatan' => 'nullable|string',
                'progress' => 'required|numeric|between:0,100',
            ]);

            if($body['jenis_dokumen'] == 'file') {
                if($request->hasFile('file')) {
                    $file = $request->file('file');
                    
                    $filename = '(FEEDBACK)_Laporan_'
                        .str_replace(' ', '_', $data->data_kp_skripsi->jenis_laporan)
                        .'_-_BAB_'
                        .$data->bab
                        .'_-_'
                        .str_replace(' ', '_', $data->data_kp_skripsi->nama_mahasiswa)
                        .'_('
                        .$data->data_kp_skripsi->nim
                        .').'
                        .$file->getClientOriginalExtension();

                    $path = Storage::disk('r2')->putFileAs('sikps/master-bimbingan', $file, $filename);

                    $url = Storage::disk('r2')->url($path);

                    $body['dokumen'] = $url;
                }
            }

            $body['status'] = 'feedback';

            $data->update($body);

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);

        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ], 500);
        }
    }

    public function delete_admin(Request $request, int $id) {
        try {
            $data = $this->model->find($id);

            if(!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak ditemukan'
                ], 404);
            }

            $data->delete();

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil dihapus'
            ], 200);

        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ], 500);
        }
    }
}
