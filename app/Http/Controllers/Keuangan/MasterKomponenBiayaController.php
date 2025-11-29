<?php

namespace App\Http\Controllers\Keuangan;

use App\Http\Controllers\Controller;
use App\Models\Keuangan\ManajemenBiaya;
use App\Models\Keuangan\MasterKomponenBiaya;
use Illuminate\Http\Request;

class MasterKomponenBiayaController extends Controller
{

    public function create(Request $request) {
        try {
            $validated = $request->validate([
                'tahun_angkatan' => 'required',
                'mhs_id' => 'required',
                'biaya_dipilih' => 'required|array',
                'nama_komponen' => 'required|array',
                'persentase_beasiswa' => 'required|array',
                'jumlah' => 'required|array',
            ]);

            $mhs_id = $validated['mhs_id'];

            // Cek apakah sudah ada data untuk mhs_id dengan status = 1
            $exists = ManajemenBiaya::where('mhs_id', $mhs_id)
                ->where('status', 1)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data untuk mahasiswa ini dengan status aktif sudah ada.'
                ], 409);
            }

            $biaya_dipilih = $validated['biaya_dipilih'];
            $nama_komponen = $validated['nama_komponen'];
            $persentase_beasiswa = $validated['persentase_beasiswa'];

            // Hapus data lama mahasiswa ini dulu (optional, kalau ingin update replace)
            ManajemenBiaya::where('mhs_id', $mhs_id)->delete();

            foreach ($biaya_dipilih as $index) {
                $komponen = MasterKomponenBiaya::where('tahun_angkatan', $validated['tahun_angkatan'])
                    ->where('nama_komponen', $nama_komponen[$index])
                    ->first();

                if (!$komponen) {
                    return response()->json([
                        'success' => false,
                        'message' => "Komponen biaya tidak ditemukan untuk nama '{$nama_komponen[$index]}' dan tahun '{$validated['tahun_angkatan']}'"
                    ], 404);
                }

                $kewajiban = $komponen->kewajiban; // Misalnya field ini bernama "kewajiban" atau "jumlah"
                $persentase = (float) $persentase_beasiswa[$index];
                $potongan = ($kewajiban * $persentase) / 100;
                $jumlahAkhir = $kewajiban - $potongan;

                ManajemenBiaya::create([
                    'mhs_id' => $mhs_id,
                    'id_komponen_biaya' => $komponen->id_komponen,
                    'persentase_beasiswa' => $persentase,
                    'potongan_beasiswa' => $potongan,
                    'jumlah' => $jumlahAkhir,
                    'status' => 1,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Manajemen biaya berhasil ditambahkan!'
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
