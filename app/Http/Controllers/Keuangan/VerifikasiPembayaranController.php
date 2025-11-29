<?php

namespace App\Http\Controllers\Keuangan;

use App\Http\Controllers\Controller;
use App\Models\Keuangan\BiayaPerMahasiswa;
use App\Models\Keuangan\DetailPembayaran;
use App\Models\Keuangan\MasterNotifikasi;
use App\Models\Keuangan\MasterPembayaran;
use App\Models\Users\MahasiswaView;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VerifikasiPembayaranController extends Controller
{
    public function getAll(Request $request) {
        Carbon::setLocale('id');

        $data = MasterPembayaran::with('detailPembayaran.biayaPerMahasiswa.m_komponen_biaya')->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function verifikasi_pembayaran (Request $request) {
        try {
            $request->validate([
                'id_pembayaran' => 'required',
                'verifikasi_komponen' => 'array',
                'verifikasi_komponen.*' => '',
                'catatan' => 'nullable|string',
            ]);

            $body = $request->all();

            $pembayaran = MasterPembayaran::find($body['id_pembayaran']);

            if(!$pembayaran) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Pembayaran tidak ditemukan'
                ], 404);
            }

            $pembayaran->catatan = $body['catatan'];
            $pembayaran->save();

            // Reset semua status_verifikasi ke false
            DetailPembayaran::where('id_pembayaran', $pembayaran->id_pembayaran)
                ->update(['status_verifikasi' => false]);

            // Update komponen yang diceklis
            if ($request->has('verifikasi_komponen')) {
                foreach ($request->verifikasi_komponen as $idDetail) {
                    $detail = DetailPembayaran::findOrFail($idDetail);
                    $detail->status_verifikasi = true;
                    $detail->save();

                    // Update sisa
                    $biaya = BiayaPerMahasiswa::find($detail->id_biaya_per_mahasiswa);
                    if ($biaya) {
                        $biaya->sisa = max(0, $biaya->sisa - $detail->nominal_bayar);
                        $biaya->save();
                    }
                }
            }

            $notif = MasterNotifikasi::where('id_pembayaran', $body['id_pembayaran'])->get();
            if ($notif->count() > 0) {
                foreach ($notif as $item) {
                    $item->update([
                        'status' => 0
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Verifikasi pembayaran berhasil disimpan.'
            ]);
        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => $error->getMessage(),
                'error' => $error
            ], 500);
        }
    }
}
