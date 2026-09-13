<?php

namespace App\Http\Controllers\Authentications;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\Authentications\LoginHistory;
use App\Models\Users\Site;
use App\Models\Users\UserSitesView;
// ? JWT
use App\Support\SiteUrl;
use Carbon\Carbon;
// ? Models - view
use ErrorException;
// ? Models - Tables
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function userLogin(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            $credentials = $request->only('email', 'password');
            $platform = $request->query('platform');

            if (! $platform) {
                return response()->json([
                    'success' => 'fail',
                    'message' => 'Nilai query platform pada url diperlukan',
                ], 400);
            }

            $ttl = self::setExpirationToken($platform);
            $token = auth()->setTTL($ttl)->attempt($credentials);

            if ($token) {
                $roles = collect(auth()->user())->filter(function ($item) {
                    if (is_bool($item)) {
                        return $item;
                    }
                })->toArray();

                if (isset($roles['is_staff'])) {
                    $staff = $this->getUserAuth();
                    $staffPositions = collect($staff)->filter(function ($item) {
                        if (is_bool($item)) {
                            return $item;
                        }
                    })->toArray();

                    /**
                     * keperluan untuk sistem surat
                     * jika user is_wk dan is_staff, hapus is_staff
                     *
                     * 31-08-2024 - Disable dulu aja
                     */
                    // if (isset($roles['is_staff']) and isset($roles['is_wk'])) {
                    //     unset($roles['is_staff']);
                    // }

                    if (count($staffPositions) > 0) {
                        // untuk sementara ambil sekretaris saja
                        if (isset($staffPositions['is_secretary'])) {
                            // $mergedRoles = array_merge($roles, [
                            //     'is_secretary' => $staffPositions['is_secretary']
                            // ]);
                            $roles = [
                                'is_secretary' => $staffPositions['is_secretary'],
                            ];
                        }

                        // $mergedRoles = array_merge(($roles->toArray()), ($staffPositions->toArray()));
                    }
                }

                $data = [
                    'token' => [
                        'access_token' => $token,
                        'token_type' => 'bearer',
                        'expires_in' => $platform === 'android' ? null : "$ttl minutes",
                    ],
                    'platform' => $platform,
                    'roles' => isset($mergedRoles) ? $mergedRoles : $roles,
                ];

                if ($platform === 'android') {
                    $loginHistory = self::getOrSetLoginHistory($token);

                    if ($loginHistory !== 'success') {
                        auth()->logout(true);

                        return $loginHistory;
                    }
                }
            } else {
                return response()->json([
                    'status' => 'fail',
                    'message' => 'Kredensial yang Anda berikan tidak sesuai',
                ], 401);
            }
        } catch (JWTException $e) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Gagal generate token',
            ], 500);
        } catch (ErrorException $e) {
            return response()->json([
                'status' => 'fail',
                'message' => $e,
            ], 500);
        }

        return $this->successfulResponseJSON($data, null, 201);
    }

    public function userLogout()
    {
        $token = JWTAuth::getToken()->get();

        if ($token) {
            JWTAuth::invalidate(true);
        }

        auth()->logout();

        return response()->json([
            'status' => 'success',
            'message' => 'Logout berhasil. Access token telah dihapus',
        ], 200);
    }

    public function validateToken()
    {
        try {
            return $this->successfulResponseJSON([
                'token' => JWTAuth::getToken()->get(),
            ], 'Access token OK!');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function validateUserSiteAccess(Request $request)
    {
        try {
            $userId = auth()->user()->id;
            $site = SiteUrl::canonical($request->query('url'));
            $userSite = SiteUrl::first(UserSitesView::where('user_id', $userId), $site);

            if ($userSite) {
                return $this->successfulResponseJSON([
                    'access' => true,
                    'site' => collect([$userSite]),
                ]);
            }

            return response()->json([
                'status' => 'fail',
                'message' => 'Pengguna dengan email <b>'
                    .auth()->user()->email
                    .' tidak memiliki akses</b> ke alamat <i>'
                    .$site
                    ."</i>.<br/>Please <a href='$site/logout' rel='noopener'><b>Logout</b></a>.",
            ], 403);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function getSite(Request $request)
    {
        try {
            if ($request->query('url')) {
                $site = SiteUrl::first(Site::query(), $request->query('url'));

                if ($site) {
                    return $this->successfulResponseJSON([
                        'site' => $site,
                    ]);
                }
            }

            return $this->failedResponseJSON('Url tidak ditemukan', 404);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function checkUrl(Request $request)
    {
        try {
            $url = $request->query('link');

            if ($url) {
                $site = SiteUrl::first(Site::query(), $url);

                if ($site) {
                    return $this->successfulResponseJSON([
                        'site' => $site,
                    ]);
                }
            }

            return $this->failedResponseJSON('URL tidak ditemukan', 404);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    /**
     * setExpirationToken
     * Fungsi untuk mengatur waktu kadaluarsa access token
     *
     * @param  string  $platform  berisi nilai 'android' atau 'web'
     *
     * Jika 'android', maka tidak memiliki kadaluarsa.
     * Jika 'web', maka memiliki waktu kadaluarsa selama 6 jam
     */
    private function setExpirationToken(string $platform)
    {
        if ($platform == 'android') {
            return 60 * 24 * 30 * 12 * 1000;
        } // kurang lebih 1000 tahun
        elseif ($platform == 'web') {
            return 60 * 6;
        } // 6 hours
    }

    private function getOrSetLoginHistory($token)
    {
        /**
         * Periksa beberapa kondisi berikut:
         * - Jika belum ada pada tabel login_histories, maka bisa insert
         * - Jika telah ada pada tabel login_histories, berarti telah login,
         * maka user tidak bisa login untuk kedua kalinya (harus dihapus dulu lewat admin)
         * - Jika status is_active adalah false
         * maka user tidak bisa login menggunakan perangkat android mana pun
         */
        $tokenExists = LoginHistory::where('user_id', auth()->user()->id)
            ->where('platform', 'android')
            ->first();

        /**
         * akun baru pertama kali login di android
         * maka insert data autentikasi akun tersebut
         */
        if (! $tokenExists) {
            $data = [
                'user_id' => auth()->user()->id,
                'platform' => 'android',
                'last_token' => $token,
                'is_active' => true,
                'login_at' => Carbon::now(),
            ];

            DB::beginTransaction();
            $create = LoginHistory::create($data);

            if ($create) {
                DB::commit();

                return 'success';
            }

            DB::rollBack();

            return $this->failedResponseJSON('Login gagal dilakukan');
        }

        /**
         * akun masih aktif dan telah login diperangkat lain
         */
        if ($tokenExists['is_active']) {
            return $this->failedResponseJSON(
                'Akun Anda telah login pada perangkat lain. Silahkan logout terlebih dahulu, kemudian hubungi Admin untuk menghapus sesi aktif milik Anda', 400
            );
        }

        /**
         * akun sudah tidak aktif
         */
        if (! $tokenExists['is_active']) {
            return $this->failedResponseJSON(
                'Akses akun Anda ke aplikasi Android telah ditutup oleh Admin', 400
            );
        }
    }
}
