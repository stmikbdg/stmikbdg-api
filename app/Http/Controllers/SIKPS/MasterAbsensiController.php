<?php

namespace App\Http\Controllers\SIKPS;

use App\Http\Controllers\Controller;
use App\Models\SIKPS\MasterAbsensi;
use App\Models\SIKPS\MasterBimbingan;
use App\Models\SIKPS\MasterJadwal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class MasterAbsensiController extends Controller
{
    protected $model;

    public function __construct() {
        $this->model = new MasterAbsensi();
    }

    public function getAll_mahasiswa(Request $request) {
        $user = $this->getUserAuth();

        $data = MasterAbsensi::whereHas('master_bimbingan.data_kp_skripsi', function ($query) use ($user) {
            $query->where('nim', 'like', '%'.$user->nim.'%');
        })->with(['master_bimbingan.data_kp_skripsi'])->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function getAll_dospem(Request $request) {
        $user = $this->getUserAuth();

        $data = MasterAbsensi::whereHas('master_bimbingan.data_kp_skripsi', function ($query) use ($user) {
            $query->where('pembimbing', 'like', '%'.$user->nama.'%');
        })->with(['master_bimbingan.data_kp_skripsi'])->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function booking_mahasiswa(Request $request, int $jadwal_id) {
        try {
            $request->validate([
                'master_bimbingan_id' => 'required|integer',
                'topik_bimbingan' => 'required|string'
            ]);

            $body = [
                'master_bimbingan_id' => $request->master_bimbingan_id,
                'topik_bimbingan' => $request->topik_bimbingan,
                'status' => 'menunggu',
                'jadwal_id' => $jadwal_id
            ];

            $user = $this->getUserAuth();

            $master_bimbingan = MasterBimbingan::where('id', ($request->master_bimbingan_id))
                ->whereHas('data_kp_skripsi', function ($query) use ($user) {
                    $query->where('nim', $user->nim);
                })
                ->with('data_kp_skripsi')
                ->first();
            
            $master_bimbingan->master_absensi()->create($body);

            if (!$master_bimbingan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data bimbingan tidak ditemukan atau bukan milik Anda.'
                ], 404);
            }

            $now = Carbon::now();
            $today = $now->toDateString(); // format: Y-m-d
            $currentTime = $now->format('H:i');

            $master_jadwal = MasterJadwal::where('id', $jadwal_id)
                ->where('pembimbing', $master_bimbingan->data_kp_skripsi->pembimbing)
                ->where('jenis_jadwal', $master_bimbingan->data_kp_skripsi->jenis_laporan)
                ->whereDate('waktu_jadwal', '>=', $today)
                // ->whereTime('jam_mulai', '<=', $currentTime)
                // ->whereTime('jam_selesai', '>=', $currentTime)
                ->first();

            

            if(!$master_jadwal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jadwal tidak ditemukan'
                ], 404);
            }

            $master_absensi = $this->model->where('master_bimbingan_id', $request->master_bimbingan_id)->where('jadwal_id', $jadwal_id)->first();

            if($master_absensi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda sudah membooking jadwal ini'
                ], 400);
            }

            $body['jadwal_id'] = $jadwal_id;

            $data = $this->model->create($body);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function delete_booking_mahasiswa(Request $request, int $id) {
        try {

            $user = $this->getUserAuth();


            $now = Carbon::now();
            $today = $now->toDateString(); // format: Y-m-d
            $currentTime = $now->format('H:i');

            $isNotAllowed = $this->model
                ->whereHas('master_jadwal', function ($query) use ($today, $currentTime) {
                    $query->whereDate('waktu_jadwal', '<=', $today)
                        ->whereTime('jam_selesai', '<=', $currentTime);
                })
                ->whereHas('master_bimbingan.data_kp_skripsi', function ($query) use ($user) {
                    $query->where('nim', $user->nim);
                })
                ->where('status', 'hadir')
                ->with('master_jadwal', 'master_bimbingan.data_kp_skripsi')
                ->where('id', $id)
                ->first();

            if($isNotAllowed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda sudah tidak bisa menghapus booking ini, karena Anda sudah hadir di jadwal ini atau jadwal sudah selesai.'
                ], 403);
            }

            $data = $this->model->find($id);

            $data->delete();

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function feedback_dospem(Request $request, int $id) {
        try {

            $user = $this->getUserAuth();

            $body = $request->validate([
                'jenis_dokumen' => 'required|string|max:10|in:file,link',
                'dokumen' => 'nullable|string',
                'file' => 'nullable|file|mimes:pdf,doc,docx',
                'catatan' => 'required|string',
                'progress' => 'required|numeric|between:0,100',
                'status' => 'required|string|in:hadir,tidak_hadir'
            ]);

            $master_absensi = $this->model
                ->where('id', $id)
                ->whereHas('master_bimbingan.data_kp_skripsi', function ($query) use ($user) {
                    $query->where('pembimbing', 'like', '%'.$user->nama.'%');
                })
                ->with('master_jadwal', 'master_bimbingan.data_kp_skripsi')
                ->first();

            if(!$master_absensi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Absensi tidak ditemukan atau bukan milik anda.'
                ], 404);
            }

            // Cek status absensi
            if($master_absensi->status == 'hadir' || $master_absensi->status == 'tidak_hadir') {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak bisa memberikan feedback jika absensi mahasiswa tersebut sudah berstatus hadir atau tidak hadir'
                ], 403);
            }

            // Cek waktu ama tanggal
            $now = Carbon::now();
            $today = $now->toDateString(); // format: Y-m-d
            $currentTime = $now->format('H:i');

            $master_jadwal = $master_absensi->master_jadwal;
            $master_bimbingan = $master_absensi->master_bimbingan;

            if($master_jadwal->waktu_jadwal < $today || $master_jadwal->jam_selesai < $currentTime) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak bisa memberikan feedback jika jadwal sudah selesai atau belum datang'
                ], 403);
            }

            if($body['status'] == 'tidak_hadir') {
                $master_absensi->update([
                    'status' => $body['status']
                ]);

                return response()->json([
                    'success' => true,
                    'data' => $master_absensi
                ]);
            }

            if($body['jenis_dokumen'] == 'file') {

                if(!$request->hasFile('file')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'File tidak boleh kosong'
                    ]);
                }

                $file = $request->file('file');
                    
                $filename = '(FEEDBACK)_Laporan_'
                    .str_replace(' ', '_', $master_bimbingan->data_kp_skripsi->jenis_laporan)
                    .'_-_'
                    .str_replace(' ', '_', $master_bimbingan->data_kp_skripsi->nama_mahasiswa)
                    .'_('
                    .$master_bimbingan->data_kp_skripsi->nim
                    .').'
                    .$file->getClientOriginalExtension();

                $path = Storage::disk('r2')->putFileAs('sikps/master-bimbingan', $file, $filename);

                $url = Storage::disk('r2')->url($path);

                $body['dokumen'] = $url;
            } else{
                if(!$request->has('dokumen')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Link tidak boleh kosong'
                    ]);
                }

                $body['dokumen'] = $request->get('dokumen');
            }

            

            // Buat dlu di master absensi

            $master_absensi->update([
                'status' => $body['status'],
                'catatan' => $body['catatan']
            ]);

            $master_absensi->master_bimbingan()->update([
                'catatan' => $body['catatan'],
                'progress' => $body['progress'],
                'dokumen' => $body['dokumen'],
                'jenis_dokumen' => $body['jenis_dokumen']
            ]);

            // Update langsung ke Master Bimbingan
            // $new_master_bimbingan = MasterBimbingan::find($master_absensi->master_bimbingan_id);

            // $new_master_bimbingan->update([
            //     'catatan' => $body['catatan'],
            //     'progress' => $body['progress'],
            //     'url' => $body['url']
            // ]);

            return response()->json([
                'success' => true,
                'data' => $master_absensi
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function cancel_booking_dospem(Request $request, int $id) {
        try {
            $user = $this->getUserAuth();

            $master_absensi = $this->model
                ->where('id', $id)
                ->whereHas('master_bimbingan.data_kp_skripsi', function ($query) use ($user) {
                    $query->where('pembimbing', 'like', '%'.$user->nama.'%');
                })
                ->with('master_jadwal', 'master_bimbingan.data_kp_skripsi')
                ->first();

            if(!$master_absensi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Absensi tidak ditemukan atau bukan milik anda.'
                ], 404);
            }

            $master_absensi->update([
                'status' => 'dibatalkan'
            ]);

            return response()->json([
                'success' => true,
                'data' => $master_absensi
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
