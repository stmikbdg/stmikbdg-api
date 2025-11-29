<?php

namespace App\Http\Controllers\Surat_V2;

use App\Http\Controllers\Controller;
use App\Models\Surat_V2\MasterPengajuan;
use App\Models\Surat_V2\MasterSurat;
use App\Models\Users\User;
use Illuminate\Http\Request;

class MasterPengajuanController extends Controller
{

    public function masterPengajuan_getAll(Request $request) {
        // if(auth()->user()->is_mhs) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Akses ditolak'
        //     ], 403);
        // }

        $data = MasterPengajuan::getMasterPengajuan()->toArray();

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    private function masterPengajuan_create_roleBased(array $body = [], array $payload = []) {
        $roles = [
            'dev',
            'doswal',
            'prodi',
            'admin',
            'dosen',
            'staff',
            'wk',
            'pimpinan',
            'dospem',
            'marketing',
            'akademik',
            'baak',
            'secretary',
            'bendahara',
            'kemahasiswaan',
        ];

        foreach ($roles as $role) {
            $isKey = "is_{$role}";
            $byKey = "by_is_{$role}_user_id";

            if (isset($payload[$isKey]) && isset($payload[$byKey])) {
                $data = User::where('id', $payload[$byKey])->where($isKey, true)->first();

                if(!$data) {
                    continue;
                }

                $body[$isKey] = $payload[$isKey];
                $body[$byKey] = $payload[$byKey];
            }
        }

        return $body;

    }

    private function masterPengajuan_update_roleBased(array $body = [], array $payload = []) {
        $roles = [
            'dev',
            'doswal',
            'prodi',
            'admin',
            'dosen',
            'staff',
            'wk',
            'pimpinan',
            'dospem',
            'marketing',
            'akademik',
            'baak',
            'secretary',
            'bendahara',
            'kemahasiswaan',
        ];

        foreach ($roles as $role) {
            $isKey = "is_{$role}";
            $byKey = "by_is_{$role}_user_id";

            if (isset($payload[$isKey]) && isset($payload[$byKey])) {
                if(!$payload[$isKey]) {
                    $body[$isKey] = false;
                    $body[$byKey] = null;
                }else{
                    $data = User::where('id', $payload[$byKey])->where($isKey, true)->first();
    
                    if(!$data) {
                        continue;
                    }
    
                    $body[$isKey] = $payload[$isKey];
                    $body[$byKey] = $payload[$byKey];
                }
            }
        }

        return $body;

    }

    public function masterPengajuan_create(Request $request) {
        try {
            $request->validate([
                'nama_pengajuan' => 'required|string',
                'jenis_surat_akhir' => 'required|integer',
                'input_pertanyaan' => 'required|array',
                'input_pertanyaan.*.pertanyaan' => 'required|string',
                'input_pertanyaan.*.jenis_input' => 'required|string',
                'status' => 'nullable|string',
                'is_mhs' => 'nullable|boolean',
                'is_dev' => 'nullable|boolean',
                'is_doswal' => 'nullable|boolean',
                'is_prodi' => 'nullable|boolean',
                'is_admin' => 'nullable|boolean',
                'is_dosen' => 'nullable|boolean',
                'is_staff' => 'nullable|boolean',
                'is_wk' => 'nullable|boolean',
                'is_pimpinan' => 'nullable|boolean',
                'is_dospem' => 'nullable|boolean',
                'is_marketing'  => 'nullable|boolean',
                'is_akademik' => 'nullable|boolean',
                'is_baak' => 'nullable|boolean',
                'is_secretary' => 'nullable|boolean',
                'is_bendahara' => 'nullable|boolean',
                'is_kemahasiswaan' => 'nullable|boolean',
                'by_is_mhs_user_id' => 'nullable|integer',
                'by_is_dev_user_id' => 'nullable|integer',
                'by_is_doswal_user_id' => 'nullable|integer',
                'by_is_prodi_user_id' => 'nullable|integer',
                'by_is_admin_user_id' => 'nullable|integer',
                'by_is_dosen_user_id' => 'nullable|integer',
                'by_is_staff_user_id' => 'nullable|integer',
                'by_is_wk_user_id' => 'nullable|integer',
                'by_is_pimpinan_user_id' => 'nullable|integer',
                'by_is_dospem_user_id' => 'nullable|integer',
                'by_is_marketing_user_id'  => 'nullable|integer',
                'by_is_akademik_user_id' => 'nullable|integer',
                'by_is_baak_user_id' => 'nullable|integer',
                'by_is_secretary_user_id' => 'nullable|integer',
                'by_is_bendahara_user_id' => 'nullable|integer',
                'by_is_kemahasiswaan_user_id' => 'nullable|integer',
                'for_admin' => 'boolean|nullable',
                'for_mhs' => 'boolean|nullable',
                'minimum_semester' => 'string|nullable'
            ]);

            $payload = $request->all();

            $body = [
                'pilih_surat' => $payload['jenis_surat_akhir'],
                'nama_pengajuan' => $payload['nama_pengajuan'],
                'minimum_semester' => $payload['minimum_semester'],
            ];

            if(isset($payload['status'])) {
                $body['status'] = $payload['status'];
            }

            $body = $this->masterPengajuan_create_roleBased($body, $payload);
            // dd($body);

            $master_surat = MasterSurat::find($payload['jenis_surat_akhir']);

            if(!$master_surat) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jenis Surat Akhir tidak ditemukan!'
                ], 404);
            }

            $master_pengajuan = MasterPengajuan::create($body);

            $pengajuan_pertanyaan = $master_pengajuan->pengajuan_pertanyaan()->createMany($payload['input_pertanyaan']);

            $master_pengajuan['pengajuan_pertanyaan'] = $pengajuan_pertanyaan;
    
            return response()->json([
                'success' => true,
                'message' => 'Data master pengajuan berhasil ditambahkan.',
                'data' => $master_pengajuan
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e,
            ], 500);
        }
    }

    public function masterPengajuan_update(Request $request, int $id) {
        try {
            $request->validate([
                'nama_pengajuan' => 'required|string',
                'jenis_surat_akhir' => 'required|integer',
                'input_pertanyaan' => 'required|array',
                'input_pertanyaan.*.pertanyaan' => 'required|string',
                'input_pertanyaan.*.jenis_input' => 'required|string',
                'status' => 'nullable|string',
                'is_mhs' => 'nullable|boolean',
                'is_dev' => 'nullable|boolean',
                'is_doswal' => 'nullable|boolean',
                'is_prodi' => 'nullable|boolean',
                'is_admin' => 'nullable|boolean',
                'is_dosen' => 'nullable|boolean',
                'is_staff' => 'nullable|boolean',
                'is_wk' => 'nullable|boolean',
                'is_pimpinan' => 'nullable|boolean',
                'is_dospem' => 'nullable|boolean',
                'is_marketing'  => 'nullable|boolean',
                'is_akademik' => 'nullable|boolean',
                'is_baak' => 'nullable|boolean',
                'is_secretary' => 'nullable|boolean',
                'is_bendahara' => 'nullable|boolean',
                'is_kemahasiswaan' => 'nullable|boolean',
                'by_is_mhs_user_id' => 'nullable|integer',
                'by_is_dev_user_id' => 'nullable|integer',
                'by_is_doswal_user_id' => 'nullable|integer',
                'by_is_prodi_user_id' => 'nullable|integer',
                'by_is_admin_user_id' => 'nullable|integer',
                'by_is_dosen_user_id' => 'nullable|integer',
                'by_is_staff_user_id' => 'nullable|integer',
                'by_is_wk_user_id' => 'nullable|integer',
                'by_is_pimpinan_user_id' => 'nullable|integer',
                'by_is_dospem_user_id' => 'nullable|integer',
                'by_is_marketing_user_id'  => 'nullable|integer',
                'by_is_akademik_user_id' => 'nullable|integer',
                'by_is_baak_user_id' => 'nullable|integer',
                'by_is_secretary_user_id' => 'nullable|integer',
                'by_is_bendahara_user_id' => 'nullable|integer',
                'by_is_kemahasiswaan_user_id' => 'nullable|integer',
                'for_admin' => 'boolean|nullable',
                'for_mhs' => 'boolean|nullable',
                'minimum_semester' => 'integer|nullable'
            ]);

            $payload = $request->all();

            $body = $payload;

            $body['pilih_surat'] = $payload['jenis_surat_akhir'];

            $body['input_pertanyaan'] = $payload['input_pertanyaan'];
            $body['minimum_semester'] = $payload['minimum_semester'];

            unset($body['jenis_surat_akhir']);

            if(isset($payload['status'])) {
                $body['status'] = $payload['status'];
            }

            $body = $this->masterPengajuan_update_roleBased($body, $payload);

            // return response()->json([
            //     'payload' => $request->all(),
            //     'body' => $body
            // ]);

            $master_surat = MasterSurat::findOrFail($payload['jenis_surat_akhir']);
    
            $master_pengajuan = MasterPengajuan::findOrFail($id);


            $master_pengajuan->update($body);

            $master_pengajuan->pengajuan_pertanyaan()->delete();

            $pengajuan_pertanyaan = $master_pengajuan->pengajuan_pertanyaan()->createMany($payload['input_pertanyaan']);

            $master_pengajuan['pengajuan_pertanyaan'] = $pengajuan_pertanyaan;
    
            return response()->json([
                'success' => true,
                'message' => 'Data master pengajuan berhasil diubah.',
                'data' => $master_pengajuan
            ], 200);

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

    public function masterPengajuan_delete(Request $request, int $id) {
        try {
    
            $data = MasterPengajuan::with('pengajuan_pertanyaan', 'master_surat')->findOrFail($id);

            $data->delete();
    
            return response()->json([
                'success' => true,
                'message' => 'Data master pengajuan berhasil dihapus.',
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
            ]);
        }
    }
}
