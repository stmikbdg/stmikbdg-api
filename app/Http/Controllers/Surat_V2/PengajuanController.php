<?php

namespace App\Http\Controllers\Surat_V2;

use App\Http\Controllers\Controller;
use App\Models\Surat_V2\MasterPengajuan;
use App\Models\Surat_V2\NoSurat;
use App\Models\Surat_V2\Pengajuan;
use App\Models\Surat_V2\PengajuanPersetujuan;
use App\Models\Surat_V2\PengajuanPertanyaan;
use App\Models\Users\Mahasiswa;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PengajuanController extends Controller
{
    public function pengajuanMahasiswa_getAll(Request $request) {
        $account = auth()->user();
        $data = Pengajuan::with('master_pengajuan.master_surat', 'user', 'pengajuan_jawaban.pengajuan_pertanyaan', 'pengajuan_persetujuan', 'nomor_surat')
            ->whereHas('master_pengajuan', function ($query) use ($account) {
                $query
                    ->where('is_admin', $account->is_admin)
                    ->orWhere('is_prodi', $account->is_prodi)
                    ->orWhere('is_doswal', $account->is_doswal)
                    ->orWhere('is_dosen', $account->is_dosen)
                    ->orWhere('is_staff', $account->is_prodi)
                    ->orWhere('is_dospem', $account->is_dospem);
            })
            ->get();
        $data_nims = $data->map(function ($item) {
            return explode('-', $item->user->kd_user)[1];
        });
        $mahasiswa = Mahasiswa::whereIn('nim', $data_nims)->get();

        return response()->json([
            'success' => true,
            'data' => $data->map(function ($item) use ($mahasiswa) {
                $item['user']['alamat'] = $mahasiswa->where('nim', explode('-', $item->user->kd_user)[1])->first()->alamat;
                $item['user']['tmp_lahir'] = $mahasiswa->where('nim', explode('-', $item->user->kd_user)[1])->first()->tmp_lahir;
                $item['user']['tgl_lahir'] = $mahasiswa->where('nim', explode('-', $item->user->kd_user)[1])->first()->tgl_lahir;
                return $item;
            })
        ], 200);
    }

    public function pengajuanMahasiswa_getById(Request $request, int $id) {
        $data = Pengajuan::getById($id)->first();

        if(!$data) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.',
            ], 404);
        }

        $user = $data['user'];

        if(!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Data Pengguna dari Pengajuan ini tidak ditemukan.',
            ], 404);
        }

        if(!$user->is_mhs) {
            return response()->json([
                'success' => false,
                'message' => 'Data Pengguna dari Pengajuan ini bukanlah mahasiswa.',
            ], 404);
        }

        if(empty($user->kd_user)) {
            return response()->json([
                'success' => false,
                'message' => 'Data Pengguna dari Pengajuan ini tidak memiliki Kode Pengguna.',
            ], 404);
        }

        $kd_user = $user->kd_user;

        if(!str_contains($kd_user, 'MHS-')) {
            return response()->json([
                'success' => false,
                'message' => 'Data Pengguna dari Pengajuan ini tidak memiliki Kode Pengguna yang sesuai.',
            ]);
        }

        $nim = explode('-', $kd_user)[1];

        $mahasiswa = Mahasiswa::with('jurusan')->where('nim', $nim)->first();

        if(!$mahasiswa) {
            return response()->json([
                'success' => false,
                'message' => 'Data Mahasiswa dari Pengajuan ini tidak ditemukan.',
            ], 404);
        }

        $data['user']['nama_mahasiswa'] = $mahasiswa['nm_mhs'];
        $data['user']['nim'] = $mahasiswa['nim'];
        $data['user']['alamat'] = $mahasiswa['alamat'];
        $data['user']['tmp_lahir'] = $mahasiswa['tmp_lahir'];
        $data['user']['tgl_lahir'] = $mahasiswa['tgl_lahir'];
        $data['user']['jurusan'] = $mahasiswa['jurusan']['nama_jurusan'];
        $data['user']['prodi'] = $mahasiswa['jurusan']['prodi'];
        $data['user']['jenis_mahasiswa'] = ($mahasiswa['jns_mhs'] == 'R')
            ? 'Reguler'
            : (($mahasiswa['jns_mhs'] === 'E') ? 'Eksekutif' : 'Karyawan');

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function pengajuanMahasiswa_create(Request $request) {
        try {
            $request->validate([
                'master_pengajuan_id' => 'integer|required',
                // 'status' => 'string|nullable',
                'jawaban' => 'array|required',
                'jawaban.*.pertanyaan_id' => 'integer|required',
                'jawaban.*.jawaban' => 'string|required'
            ]);

            if(!auth()->user()->is_mhs) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fitur ini hanya diperbolehkan untuk mahasiswa saja.'
                ], 403);
            }

            $user = collect(auth()->user())->filter(function ($item) {
                return $item;
            });

            $payload = $request->all();

            $master_pengajuan = MasterPengajuan::find($payload['master_pengajuan_id']);

            if(!$master_pengajuan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Master Pengajuan tidak ditemukan.'
                ], 404);
            }

            $pertanyaan_ids = collect($payload['jawaban'])->pluck('pertanyaan_id')->toArray();

            $pengajuan_pertanyaan = PengajuanPertanyaan::whereIn('id', $pertanyaan_ids)
                ->where('master_pengajuan_id', $payload['master_pengajuan_id'])
                ->get();

            if($pengajuan_pertanyaan->count() != count($pertanyaan_ids)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Pertanyaan tidak ditemukan.'
                ], 404);
            }

            $body = [
                'master_pengajuan_id' => $payload['master_pengajuan_id'],
                'user_id' => $user['id']
            ];

            if(isset($payload['status'])) {
                $body['status'] = $payload['status'];
            }

            $pengajuan = Pengajuan::create($body);

            $pengajuan_jawaban = $pengajuan->pengajuan_jawaban()->createMany($payload['jawaban']);

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan berhasil dibuat.',
                'data' => $pengajuan
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.',
                'error' => $e
            ], 404);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }

    public function pengajuanMahasiswa_update(Request $request, int $id) {
        try {
            $request->validate([
                'master_pengajuan_id' => 'integer|required',
                // 'status' => 'string|nullable',
                'jawaban' => 'array|required',
                'jawaban.*.pertanyaan_id' => 'integer|required',
                'jawaban.*.jawaban' => 'string|required'
            ]);

            if (!auth()->user()->is_mhs) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fitur ini hanya diperbolehkan untuk mahasiswa saja.'
                ], 403);
            }

            $user = auth()->user();

            $pengajuan = Pengajuan::find($id);

            if (!$pengajuan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengajuan tidak ditemukan.'
                ], 404);
            }

            if ($pengajuan->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke pengajuan ini.'
                ], 403);
            }

            $master_pengajuan = MasterPengajuan::find($request->master_pengajuan_id);
            if (!$master_pengajuan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Master Pengajuan tidak ditemukan.'
                ], 404);
            }

            $pertanyaan_ids = collect($request->jawaban)->pluck('pertanyaan_id')->toArray();
            $pengajuan_pertanyaan = PengajuanPertanyaan::whereIn('id', $pertanyaan_ids)
                ->where('master_pengajuan_id', $request->master_pengajuan_id)
                ->get();

            if ($pengajuan_pertanyaan->count() != count($pertanyaan_ids)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Pertanyaan tidak ditemukan.'
                ], 404);
            }

            // Update the Pengajuan record
            $pengajuan->update([
                'master_pengajuan_id' => $request->master_pengajuan_id
            ]);

            // Optional: delete existing jawaban and re-insert
            $pengajuan->pengajuan_jawaban()->delete();
            $pengajuan->pengajuan_jawaban()->createMany($request->jawaban);

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan berhasil diperbarui.',
                'data' => $pengajuan
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.',
                'error' => $e
            ], 404);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }

    public function pengajuanMahasiswa_delete(Request $request, int $id) {
        try {
            if (!auth()->user()->is_mhs and !auth()->user()->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fitur ini hanya diperbolehkan untuk mahasiswa dan admin saja.'
                ], 403);
            }

            $pengajuan = Pengajuan::with('master_pengajuan.master_surat')->find($id);

            if (!$pengajuan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengajuan tidak ditemukan.'
                ], 404);
            }

            

            // Update the Pengajuan record
            $pengajuan->delete();

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan berhasil dihapus.',
                'data' => $pengajuan
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.',
                'error' => $e
            ], 404);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }

    public function pengajuanMahasiswa_verify(Request $request, int $pengajuan_id) {
        try {
            if (auth()->user()->is_mhs) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya Non-Mahasiswa yang bisa mengakses fitur ini.'
                ], 403);
            }

            $user = collect(auth()->user())->filter(function ($item) {
                return $item;
            });

            $body = $request->validate([
                'status' => 'required|string', // contoh: "diterima" atau "ditolak"
                'role' => 'required|string', // contoh: 'is_admin', 'is_mhs', dll.
                'komentar' => 'nullable|string',
                'value' => 'required|boolean',  // 'true' atau 'false'
            ]);

            if(!auth()->user()->{$body['role']}) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki hak akses.'
                ], 403);
            }
            
            $pengajuan = Pengajuan::with('master_pengajuan')->where('id', $pengajuan_id)->first();
            
            

            if (!$pengajuan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Pengajuan tidak ditemukan.'
                ], 404);
            }
            
            if(empty($pengajuan->master_pengajuan->{$body['role']})) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki hak akses untuk melakukan verifikasi ini.'
                ], 403);
            }

            // dd($pengajuan);

            
            $pengajuan_persetujuan = PengajuanPersetujuan::where('pengajuan_id', $pengajuan_id)
                ->where($body['role'], true)
                ->first();

                
                
            if(!$pengajuan_persetujuan) {
                // dd('atas');
                $payload = [
                    'pengajuan_id' => $pengajuan_id,
                    'komentar' => $body['komentar'],
                    $body['role'] => true,
                    'status' => $body['status'],
                    'user_id' => $user['id'],
                    'value' => $body['value']
                ];

                // dd($payload);
                $pengajuan_persetujuan_new = PengajuanPersetujuan::create([
                    'pengajuan_id' => $pengajuan_id,
                    'komentar' => $body['komentar'],
                    $body['role'] => true,
                    'status' => $body['status'],
                    'user_id' => $user['id'],
                    'value' => $body['value']
                ]); 
            }else{
                if($pengajuan_persetujuan->user_id != $user['id']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki hak akses untuk mengubah verifikasi ini.'
                    ], 403);
                }

                $pengajuan_persetujuan_new = $pengajuan_persetujuan->first();
                $pengajuan_persetujuan_new->update([
                    'komentar' => $body['komentar'],
                    $body['role'] => true,
                    'status' => $body['status'],
                    'user_id' => $user['id'],
                    'value' => $body['value']
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan berhasil diverifikasi.'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.',
                'error' => $e
            ], 404);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ]);
        }
    }

    public function storage_upload(Request $request) {
        try {
            $request->validate([
                'file' => 'required|file|mimes:pdf,doc,docx,jpg,png,jpeg',
            ]);

            $now = Carbon::now()->format('Ymd_His');

            $uuid = Str::uuid()->toString();

            $file = $request->file('file');
            $filename = $now . '_' . $uuid . '.' . $file->getClientOriginalExtension();

            $path = Storage::disk('r2')->putFileAs('surat/v2', $file, $filename);

            $url = Storage::disk('r2')->url($path);

            return response()->json([
                'success' => true,
                'message' => 'File berhasil diupload.',
                'data' => [
                    'url' => $url
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

    public function noSuratAdd(Request $request, int $pengajuan_id) {
        try {
            $nomor_surat = NoSurat::where('pengajuan_id', $pengajuan_id)->first();

            $data = null;

            if(!$nomor_surat) {
                $data = NoSurat::create([
                    'pengajuan_id' => $pengajuan_id,
                    'nomor_surat' => $request->input('nomor_surat')
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Nomor surat berhasil ditambahkan!',
                'data' => $data
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
