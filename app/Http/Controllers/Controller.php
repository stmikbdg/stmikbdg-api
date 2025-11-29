<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

// ? Exception
use App\Exceptions\ErrorHandler;
use App\Models\Users\Admin;

// ? Models - view
use App\Models\Users\MahasiswaView;
use App\Models\Users\Dosen;
use App\Models\Users\AllStaffView;
use Illuminate\Support\Facades\Storage;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Digunakan untuk mendapatkan data user yang telah terautentikasi
     * Data diambil dari view dosen atau view mahasiswa dari database 'stmikbdg_dummy'
     */
    public function getUserAuth() {
        try {
            $isDosen = auth()->user()->is_dosen;
            $isAdmin = auth()->user()->is_admin;
            $isMhs = auth()->user()->is_mhs;
            $isStaff = auth()->user()->is_staff;

            $kdUserArr = explode('-', auth()->user()->kd_user);
            $userIdentifier = $kdUserArr[1];

            /**
             * Jika dosen true, maka get dari db lama table dosen
             * sehingga data user harus ada di table dosen
             *
             * termasuk:
             * - is_doswal
             * - is_prodi
             * - is_wk
             */
            if ($isDosen) {
                $dosen = Dosen::where('kd_dosen', $userIdentifier)->first();

                if ($dosen) {
                    // buat nm_dosen menjadi key nama
                    $tempNama = $dosen['nm_dosen'];
                    unset($dosen['nm_dosen']);

                    // cek jika field nama telah memiliki gelar
                    $explodedNamaDosen =  explode(',', trim($tempNama));
                    $dosen['nama'] = $explodedNamaDosen[0];
                    $dosen['nama_dan_gelar'] = $dosen['nama'] . ', ' . $dosen['gelar'];

                    return $dosen;
                }
            }

            /**
             * jika admin true, maka get dari db baru table admins
             */
            if ($isAdmin) {
                $admin = Admin::where('kd_admin', $userIdentifier)->first();

                if ($admin) {
                    // buat nm_admin menjadi key nama
                    $tempNama = $admin['nm_admin'];
                    unset($admin['nm_admin']);
                    $admin['nama'] = $tempNama;

                    return $admin;
                }
            }

            // user adalah staff
            if ($isStaff) {
                $staff = AllStaffView::where('user_id', auth()->user()->id)->first();

                return $staff;
            }

            // user adalah mahasiswa
            if ($isMhs) {
                $mahasiswa = MahasiswaView::where('nim', $userIdentifier)->first();

                if ($mahasiswa) {
                    // buat nm_mhs menjadi key nama
                    $tempNama = $mahasiswa['nm_mhs'];
                    unset($mahasiswa['nm_mhs']);
                    $mahasiswa['nama'] = $tempNama;

                    return $mahasiswa;
                }
            }
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function debug_log(array $data = []) {
        return response()->json([
            'success' => true,
            'message' => 'This is debugging data',
            'data' => $data
        ]);
    }

    public function isDosenWali($dosen, $mhsId) {
        try {
            $mahasiswa = MahasiswaView::where('mhs_id', $mhsId)->first();

            return $dosen['dosen_id'] === $mahasiswa['dosen_id'] ?? false;
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function successfulResponseJSON(array $data, string $message = null, int $statusCode = 200) {
        if (is_null($message)) {
            return response()->json([
                'status' => 'success',
                'data' => $data,
            ], $statusCode);
        } else {
            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => $data,
            ], $statusCode);
        }
    }

    public function failedResponseJSON($message = 'Gagal', $statusCode = 500) {
        return response()->json([
            'status' => 'fail',
            'message' => $message,
        ], $statusCode);
    }

    public function successfulResponseJSONV2($message = 'Sukses', $statusCode = 200) {
        return response()->json([
            'status' => 'success',
            'message' => $message,
        ], $statusCode);
    }

    public function parseFilters(array $filters = []): array {
        $parsed = [];

        foreach ($filters as $key => $value) {
            if (is_string($value) && str_contains($value, ',')) {
                // Convert comma-separated string into array
                $parsed[$key] = explode(',', $value);
            } else {
                $parsed[$key] = $value;
            }
        }

        return $parsed;
    }

    public function uploadFile(string $path, string $fileName, $file, string $storage = 'r2') {
        try {

            $storage_path = Storage::disk($storage)->putFileAs($path, $file, $fileName);

            $url = Storage::disk($storage)->url($storage_path);

            return [
                'success' => true,
                'message' => 'File berhasil diupload.',
                'data' => [
                    'url' => $url
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'error' => $e
            ];
        }
    }
}
