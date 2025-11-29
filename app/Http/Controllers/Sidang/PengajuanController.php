<?php

namespace App\Http\Controllers\Sidang;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\Sidang\Pengajuan;
use App\Models\Sidang\StatusPengajuan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use function PHPUnit\Framework\isEmpty;

class PengajuanController extends Controller
{
    public function getAllPengajuan(Request $request) {
        try{
            $pengajuan = null;
            // Admin
            if($request->query('is_admin')) {
                if(!auth()->user()->is_admin) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda bukan admin'
                    ], 403);
                }
                $pengajuan = Pengajuan::with('statusPengajuan', 'data_kp_skripsi')->get();
            }

            // Mahasiswa
            if($request->query('is_mhs')) {
                if(!auth()->user()->is_mhs) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda bukan mahasiswa'
                    ], 403);
                }
                $user = $this->getUserAuth();

                $pengajuan = Pengajuan::with('statusPengajuan', 'data_kp_skripsi')
                    ->where('nim', $user->nim)
                    ->get()
                    ->first();
            }

            if($request->query('is_dospem')) {
                if(!auth()->user()->is_dospem) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda bukan dosen pembimbing'
                    ], 403);
                }

                $dospem = $this->getUserAuth();
                $pengajuan = Pengajuan::with('statusPengajuan')
                    ->where('pembimbing', 'like', '%'.$dospem->nama.'%')
                    ->get();
            }

            // $pengajuan = Pengajuan::with('statusPengajuan')->first();
            // dd($pengajuan);
            return $this->successfulResponseJSON([
                'pengajuan' => $pengajuan
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
        
    }

    public function KirimPengajuan(Request $request)
    {
        // Validasi input (opsional tapi direkomendasikan)
        $request->validate([
            'nim' => 'required|string|max:20',
            'nama' => 'required|string|max:255',
            'jurusan' => 'required|string',
            'pembimbing' => 'required|string',
            'jenis_sidang' => 'required|string',
            'laporan_pdf' => 'required|string',
            'form_pendaftaran' => 'required|string',
            'skka' => 'required|string',
            'transkrip' => 'required|string',
            'form_bimbingan' => 'required|string',
            'nilai_e' => 'required',
            'nilai_d' => 'required',
            'no_wa' => 'required|string',
          ]);

        try {
            DB::beginTransaction();

            $pengajuan = Pengajuan::create([
                'nim' => $request->nim,
                'nama' => $request->nama,
                'jurusan' => $request->jurusan,
                'pembimbing' => $request->pembimbing,
                'jenis_sidang' => $request->jenis_sidang,
                'laporan_pdf' => $request->laporan_pdf,
                'form_pendaftaran' => $request->form_pendaftaran,
                'skka' => $request->skka,
                'transkrip' => $request->transkrip,
                'form_bimbingan' => $request->form_bimbingan,
                'nilai_e' => $request->nilai_e,
                'nilai_d' => $request->nilai_d,
                'no_wa' => $request->no_wa,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $status = StatusPengajuan::create([
                'id_pengajuan_sidang' => $pengajuan->id,
                'aprov_pembimbing' => false,
                'aprov_keuangan' => false,
                'aprov_prodi' => false,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Pengajuan berhasil disimpan',
                'pengajuan' => $pengajuan->load('statusPengajuan'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal menyimpan pengajuan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
