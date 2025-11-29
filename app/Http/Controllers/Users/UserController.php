<?php

namespace App\Http\Controllers\Users;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

// ? Models - Table
use App\Models\Users\User;
use App\Models\Users\UserSite;

// ? Models - View
use App\Models\Users\UserView;
use App\Models\Users\StaffMarketingView;

// ? Excel
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\ImportUser;
use App\Exceptions\ExcelImportException;
use App\Models\Keuangan\MasterPembayaran;
use App\Models\Keuangan\TahunAkademik;
use App\Models\TahunAjaranView;
use Illuminate\Support\Carbon;

class UserController extends Controller {
    public function addNewUser(Request $request) {
        try {
            if ($request->query('import')) {
                if ($request->query('import') === 'excel') {
                    $request->validate([
                        'file' => 'required|mimes:xlsx,xls|max:2048'
                    ]);
                    $excel = $request->file('file');
                    return self::importUserFromExcel($excel);
                } else {
                    return response()->json([
                        'status' => 'fail',
                        'message' => 'Metode import yang tersedia saat ini adalah menggunakan file excel'
                    ], 400);
                }
            }

            $validatedData = $request->validate([
                'kd_user' => 'required|string',
                'email' => 'required|string|email|unique:users',
                'password' => 'required|string|min:8|max:64|regex:/^\S*$/u',
                'is_admin' => 'nullable|boolean',
                'is_mhs' => 'nullable|boolean',
                'is_dev' => 'nullable|boolean',
                'is_dosen' => 'nullable|boolean',
                'is_doswal' => 'nullable|boolean',
                'is_prodi' => 'nullable|boolean',
            ]);

             if ($validatedData['is_admin'] and $validatedData['is_mhs']) {
                // jika admin dan mhs true
                return response()->json([
                    'status' => 'fail',
                    'message' => 'Maaf, akun mahasiswa tidak bisa menjadi Admin'
                ], 400);
            } else if ($validatedData['is_admin'] and !$validatedData['is_dosen']) {
                // jika admin saja
                $tempKdUser = 'ADM-' . $validatedData['kd_user'];
            } else {
                // jika admin dan dosen true, maka jadikan sebagai dosen
                // selain itu maka ia mahasiswa
                $tempKdUser = $validatedData['is_dosen']
                    ? 'DSN-' . $validatedData['kd_user']
                    : 'MHS-' . $validatedData['kd_user'];
            }

            $hashPassword = Hash::make($validatedData['password']);
            $validatedData['kd_user'] = $tempKdUser;
            $validatedData['password'] = $hashPassword;
            $validatedData['created_at'] = now();
            $validatedData['updated_at'] = now();
            $validatedData['is_dosen'] = $request->is_dosen ?? false;
            $validatedData['is_admin'] = $request->is_admin ?? false;
            $validatedData['is_mhs'] = $request->is_mhs ?? false;
            $validatedData['is_dev'] = $request->is_dev ?? false;
            $validatedData['is_doswal'] = $request->is_doswal ?? false;
            $validatedData['is_prodi'] = $request->is_prodi ?? false;
            $validatedData['image'] = 'college_student.png';

            // validate kd_user lagi
            Validator::make(['kd_user' => $tempKdUser], [
                'kd_user' => 'unique:users,kd_user'
            ])->validate();

            User::insert($validatedData);

            return $this->successfulResponseJSON([
                'user' => [
                    'kd_user' => $validatedData['kd_user'],
                    'email' => $validatedData['email'],
                ],
            ], 'User berhasil ditambahkan', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    // belum diperbaiki
    private function importUserFromExcel($excel) {
        try {
            $fileName = $excel->hashName();
            $path = $excel->storeAs('public/excel/', $fileName);

            Excel::import(new ImportUser, storage_path('app/public/excel/' . $fileName));

            Storage::delete($path);

            return response()->json([
                'status' => 'success',
                'message' => 'Data user pada file excel berhasil ditambahkan'
            ], 201);
        } catch (ExcelImportException $e) {
            return response()->json([
                'status' => 'fail',
                'message' => $e->getMessage()
            ], $e->getCode());
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getMyProfile() {
        try {
            $user = $this->getUserAuth();

            $account= collect(auth()->user())->filter(function ($item) {
                return $item;
            });

            /**
             * Untuk keperluan sistem surat - sementara
             * Jika is_wk dan is_staff aktif, maka hapus role is_staff
             */
            if ((isset($account['is_wk']) and isset($account['is_staff']))) {
                unset($account['is_staff']);
            }

            $keuangan = null;

            // return response()->json([
            //     'status' => 'success',
            //     'message' => 'Berhasil mengambil data profile',
            //     'data' => [
            //         'profile' => $user,
            //         'account' => $account,
            //         'keuangan' => $keuangan
            //     ]
            // ], 200);

            if(isset($account['is_mhs'])) {
                if($account['is_mhs']) {
                    if(isset($user['mhs_id'])) {
                        $keuangan = $this->cek_keuangan_mhs($user);
                    }
                }
            }

            return $this->successfulResponseJSON([
                'profile' => $user,
                'account' => $account,
                'keuangan' => $keuangan
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function cek_keuangan_mhs($profile) {

        $mhsId = $profile['mhs_id'] ?? null;
        $allTahunAkademik = TahunAkademik::getTahunAkademik([
            'status' => 1
        ]);
        $today = Carbon::now();

        $terminAktif = null;

        // 1. Cari termin berdasarkan tanggal
        foreach ($allTahunAkademik as $ta) {
            if ($today->between(Carbon::parse($ta['ganjil_mulai']), Carbon::parse($ta['ganjil_pelaksanaan_akhir']))) {
                $terminAktif = $ta['termin'];
                break;
            }
            if ($today->between(Carbon::parse($ta['genap_mulai']), Carbon::parse($ta['genap_pelaksanaan_akhir']))) {
                $terminAktif = $ta['termin'];
                break;
            }
            if ($today->between(Carbon::parse($ta['antara_mulai']), Carbon::parse($ta['antara_pelaksanaan_akhir']))) {
                $terminAktif = $ta['termin'];
                break;
            }
        }

        // 2. Ambil tahun ajaran aktif (untuk ambil tahun_id)
        $tahunAktif = TahunAjaranView::getTahunAjaran($profile);
        if(!$tahunAktif->exists()) {
            return [
                'success' => false,
                'message' => 'Belum ada tahun ajaran aktif!',
                'termin'  => 0
            ];
        }
        $semesterSekarang = $tahunAktif ?? null;
        $tahunId = $semesterSekarang['tahun_id'] ?? null;

        // 3. Kalau tidak ada termin aktif → ambil termin terakhir
        if (!$terminAktif) {
            $terminAktif = collect($allTahunAkademik)->max('termin');
        }

        // 4. Cek apakah sudah bayar di termin yang ketemu
        $sudahBayar = MasterPembayaran::where('mhs_id', $mhsId)
            ->whereHas('detailPembayaran.biayaPerMahasiswa.m_komponen_biaya', function ($query) {
                $query->whereIn('id_nama_komponen', [4, 5, 6]);
            })
            // ->whereHas('detailPembayaran', function ($query) {
            //     $query->where('status_verifikasi', 1)
            // })
            ->where('tahun_id', $tahunId)
            ->where('termin', $terminAktif)
            ->exists();

        return [
            'success'  => $sudahBayar,                   // true/false
            'message' => $sudahBayar ? 'Sudah Bayar' : 'Belum Bayar',
            'termin'  => $terminAktif
        ];
    }

    public function putMyPassword(Request $request) {
        try {
            $validatedData = $request->validate([
                'current_password' => 'required',
                'new_password' => 'required|min:8|max:64|regex:/^\S*$/u'
            ]);

            if (!Hash::check($validatedData['current_password'], auth()->user()->password)) {
                return response()->json([
                    'status' => 'fail',
                    'message' => 'Password sekarang tidak sesuai'
                ], 404);
            }

            $newPassword = Hash::make($validatedData['new_password']);

            User::where('kd_user', auth()->user()->kd_user)
                    ->update([
                        'password'=> $newPassword,
                        'updated_at' => now(),
                    ]);

            return $this->successfulResponseJSON([
                'email' => auth()->user()->email,
            ], 'Password berhasil diperbaharui');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    /**
     * ! Jangan Dihapus
     * Mau dirapihin, tapi udah terlanjur dipake di beberapa sistem
     */
    public function getUserList(Request $request) {
        try {
            $isDosen = $request->query('is_dosen')
                ? filter_var($request->query('is_dosen'), FILTER_VALIDATE_BOOLEAN)
                : null;
            $isAdmin = $request->query('is_admin')
                ? filter_var($request->query('is_admin'), FILTER_VALIDATE_BOOLEAN)
                : null;
            $isMhs = $request->query('is_mhs')
                ? filter_var($request->query('is_mhs'), FILTER_VALIDATE_BOOLEAN)
                : null;
            $isDev = $request->query('is_dev')
                ? filter_var($request->query('is_dev'), FILTER_VALIDATE_BOOLEAN)
                : null;
            $isStaff = $request->query('is_staff')
                ? filter_var($request->query('is_staff'), FILTER_VALIDATE_BOOLEAN)
                : null;

            // initial value
            $users = [];

            if ($isDosen or $isAdmin or $isMhs or $isDev or $isStaff) {
                if ($isStaff and $request->query('job')) {
                    $job = $request->query('job');

                    if (strtolower($job) == 'is_marketing') {
                        $users = StaffMarketingView::orderBy('staff_id', 'DESC')->get();
                    }
                } else {
                    $filter = [
                        'is_dosen' => $isDosen,
                        'is_admin' => $isAdmin,
                        'is_mhs' => $isMhs,
                        'is_dev' => $isDev,
                        'is_staff' => $isStaff
                    ];

                    $users = UserView::getAllUsers($filter);
                }
            } else {
                $users = UserView::getAllUsers();
            }

            return $this->successfulResponseJSON([
                'users' => $users,
            ]);
        } catch (\Exception $e) {
            return $e;
            return ErrorHandler::handle($e);
        }
    }

    public function addProfileImage(Request $request) {
        try {
            $request->validate([
                'image' => 'required|mimes:png,jpg|max:1024',
            ]);

            $image = $request->file('image');
            $fileName = $image->hashName();
            // $image->storeAs('public/users/images/', $fileName);

            // // cek old image
            // $oldImage = auth()->user()->image;

            // if ($oldImage !== config('app.url') . 'storage/users/images/college_student.png') {
            //     $pathOldImage = 'public/users/images/' . auth()->user()->image;
            //     Storage::delete($pathOldImage);
            // }

            // $imgUrl = config('app.url') . 'storage/users/images/' . $fileName;

            $response = $this->uploadFile('profile/images', $fileName, $image);

            if (!$response['success']) {
                return response()->json([
                    'status' => 'fail',
                    'message' => 'Gagal mengunggah foto profil'
                ], 500);
            }

            User::where('id', auth()->user()->id)
                ->update([
                    'image' => $response['data']['url'],
                ]);

            return $this->successfulResponseJSON([
                'image' => $response['data']['url']
            ], 'Foto profil berhasil diperbarui');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function deleteUserById($userId) {
        try {
            $user = UserView::where('id', $userId)->first();

            if ($user) {
                /**
                 * Menghapus user maka akan menghapus seluruh akses ke web jua
                 */
                UserSite::where('user_id', $userId)->delete();
                User::where('id', $userId)->delete();

                return response()->json([
                    'status' => 'success',
                    'message' => 'User dengan email ' . $user['email'] . ' berhasil dihapus'
                ], 200);
            }

            return response()->json([
                'status' => 'fail',
                'message' => 'User dengan id ' . $userId . ' tidak ditemukan'
            ], 400);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getStatistik() {
        try {
            $countMahasiswa = UserView::where('is_mhs', true)->get()->count();
            $countDosen = UserView::where('is_dosen', true)->get()->count();
            $countStaff = UserView::where('is_staff', true)->get()->count();
            $countAdmin = UserView::where('is_admin', true)
                ->whereNot('id', auth()->user()->id)
                ->get()
                ->count();

            return $this->successfulResponseJSON([
                'total_akun_mahasiswa' => $countMahasiswa,
                'total_akun_dosen' => $countDosen,
                'total_akun_staff' => $countStaff,
                'total_akun_admin' => $countAdmin,
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getAllUsers() {
        try {
            $users = UserView::whereNot('is_admin', true)
                ->select('id', 'email')
                ->orderBy('id', 'DESC')
                ->get();

            return $this->successfulResponseJSON([
                'users' => $users
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    /**
     * new get all users by roles
     */
    public function getUsersByRoles(Request $request) {
        try {
            $query = UserView::query();

            // berdasarkan query
            if (count($request->query()) > 0) {
                $explodedQueryRoles = explode(',', $request->query('roles'));

                foreach ($explodedQueryRoles as $role) {
                    $column = strtolower($role);
                    $query->orWhere($column, true);
                }

                $users = $query->select('id', 'kd_user', 'email')->get();

                return $this->successfulResponseJSON([
                    'users' => $users
                ]);
            }

            // semua users
            $users = $query->select('id', 'kd_user', 'email')->get();

            return $this->successfulResponseJSON([
                'users' => $users
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
