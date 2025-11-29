<?php

use App\Http\Controllers\Keuangan\FungsionalKaryawanController;
use App\Http\Controllers\Keuangan\GolonganKaryawanController;
use App\Http\Controllers\Keuangan\JabatanKaryawanController;
use App\Http\Controllers\Keuangan\KeuanganController;
use App\Http\Controllers\Keuangan\KomponenBiayaController;
use App\Http\Controllers\Keuangan\MasterBeasiswaController;

use App\Http\Controllers\Keuangan\ListPotonganKaryawanController;
use App\Http\Controllers\Keuangan\ListTunjanganKeluargaKaryawanController;
use App\Http\Controllers\Keuangan\ManajemenBiayaController;
use App\Http\Controllers\Keuangan\SkripsiKpKaryawanController;
use App\Http\Controllers\Keuangan\StatusKaryawanController;
use App\Http\Controllers\Keuangan\ProfileKaryawanController;
use App\Http\Controllers\Keuangan\TunjanganKaryawanController;
use App\Http\Controllers\Keuangan\MasterKomponenBiayaController;
use App\Http\Controllers\Keuangan\MhsController;
use App\Http\Controllers\Keuangan\NotifikasiController;
use App\Http\Controllers\Keuangan\VerifikasiPembayaranController;
use App\Http\Controllers\TahunAjaranController;
use Illuminate\Support\Facades\Route;


Route::middleware('auth.jwt')
    ->prefix('/keuangan')
    ->group(function () {
        // Route::get('/mahasiswa', [KeuanganController::class, 'getAllMahasiswaAktif'])->middleware('auth.admin');
        Route::prefix('/mahasiswa')
            ->group( function () {
                Route::get('/', [KeuanganController::class, 'getAllMahasiswaAktif'])->middleware('auth.admin');
                Route::get('/tahun-angkatan', [KeuanganController::class, 'getAllTahunAngkatan'])->middleware('auth.admin');
                Route::get('/total-sks/mhs_id/{mhs_id}', [KeuanganController::class, 'getTotalSksByMhsId'])->middleware('auth.admin');
                Route::get('/mata-kuliah-aktif/mhs_id/{mhs_id}', [KeuanganController::class, 'getMataKuliahAktifByMhsId'])->middleware('auth.admin');

                Route::controller(MhsController::class)
                    ->middleware('auth.mahasiswa')
                    ->group(function () {
                        Route::get('/penerima-beasiswa', 'getPenerimaBeasiswa');
                        Route::get('/biaya-per-semester', 'getBiayaPerSemester');
                        Route::get('/pembayaran-list', 'getPembayaranList');
                        Route::post('/pembayaran', 'pembayaran');
                        Route::get('/cek-status', 'cek_pembayaran_mhs');
                        Route::get('/total-sks', 'getTotalSKS');
                });

                Route::prefix('/tahun-ajaran')
                    ->middleware('auth.admin')
                    ->group(function () {
                        Route::get('/mhs_id/{mhs_id}', [TahunAjaranController::class, 'getTahunAjaranAktifByMhsId']);
                });

        });        

        Route::prefix('/tahun-akademik')
            ->group(function () {
                Route::get('/', [KeuanganController::class, 'getTahunAkademik'])->middleware('auth.admin');
                Route::post('/', [KeuanganController::class, 'tahunAkademik_create'])->middleware('auth.admin');
                Route::put('/{id}', [KeuanganController::class, 'tahunAkademik_update'])->middleware('auth.admin');
                Route::delete('/{id}', [KeuanganController::class, 'tahunAkademik_delete'])->middleware('auth.admin');
        });

        Route::prefix('/notifikasi')
            ->middleware('auth.admin')
            ->controller(NotifikasiController::class)
            ->group(function () {
                Route::put('/id/{id}', 'update');
                Route::get('/', 'get');
        });

        Route::prefix('/verifikasi-pembayaran')
            ->middleware('auth.admin')
            ->controller(VerifikasiPembayaranController::class)
            ->group(function () {
                
                Route::get('/', 'getAll');
                Route::put('/', 'verifikasi_pembayaran');
        });

        
        // Route::prefix('/master-komponen-biaya')
        //     ->group(function () {
        //         // Route::get('/', [KeuanganController::class, 'getMasterKomponenBiaya'])->middleware('auth.admin') 
        //         Route::post('/', [MasterKomponenBiayaController::class, 'create'])->middleware('auth.admin');
        //     });

        Route::prefix('/master-komponen-biaya')
            ->controller(KomponenBiayaController::class)
            ->group(function () {
                // Route::get('/', [KeuanganController::class, 'getMasterKomponenBiaya'])->middleware('auth.admin') 

                Route::get('/', 'getAll')->middleware('auth.admin');
                // Route::post('/', 'create')->middleware('auth.admin');
                // Route::prefix('/id')
                //     ->middleware('auth.admin')
                //     ->group(function () {
                //         Route::put('/{id}', 'update_by_id');
                //         // Route::delete('/{id}', 'delete_by_id');
                        
                //     });
                Route::prefix('/tahun-angkatan')
                    ->middleware('auth.admin')
                    ->group(function () {
                        Route::get('/', 'getAll_by_tahun_angkatan');
                        Route::post('/', 'create_by_tahun_angkatan');
                        Route::put('/', 'update_by_tahun_angkatan');
                        // Route::delete('/{tahun_angkatan}', 'delete_by_tahun_angkatan');
                        
                });
        });
        
        Route::prefix('/master-beasiswa')
            ->controller(MasterBeasiswaController::class)
            ->middleware('auth.admin')
            ->group(function () {
                Route::get('/', 'getAll');
                Route::post('/', 'create');
                Route::post('/id/{id}', 'update_by_id');
                Route::delete('/id/{id}', 'delete_by_id');
                Route::delete('/mhs_id/{mhs_id}', 'delete_by_mhs_id');
                Route::delete('/id_penerima/{id_penerima}', 'delete_by_id_penerima');

                Route::prefix('/filters')
                    ->group(function () {
                        Route::get('/tahun-ajaran', 'getAll_filters_tahun_ajaran');
                    });
        });

        Route::prefix('/filters')
            ->group(function () {
                Route::get('/tahun-ajaran', [TahunAjaranController::class, 'getTahunAjaranAktifV2']);
                Route::post('/', [MasterKomponenBiayaController::class, 'create'])->middleware('auth.admin');
        });

        Route::prefix('/manajemen-biaya')
            ->middleware('auth.admin')
            ->controller(ManajemenBiayaController::class)
            ->group(function () {
                Route::get('/', 'getAll');
                Route::get('/id_manajemen_biaya/{id}', 'get_by_id_manajemen_biaya');
                Route::get('/mhs_id/{mhs_id}', 'get_by_mhs_id');
                Route::post('/', 'create');
                Route::put('/id_manajemen_biaya/{id}', 'update');
        });
      
    Route::prefix('/karyawan')
        ->group(function () {
            // Route::get('/', [KeuanganController::class, 'getTahunAkademik'])->middleware('auth.admin');
            Route::apiResource('/status', StatusKaryawanController::class);
            Route::apiResource('/jabatan', JabatanKaryawanController::class);

            Route::get('/pendidikan-terakhir', [GolonganKaryawanController::class, 'allDataPendidikanTerakhir']);
            Route::apiResource('/golongan', GolonganKaryawanController::class);


            Route::apiResource('/fungsional', FungsionalKaryawanController::class);
            Route::apiResource('/list-potongan', ListPotonganKaryawanController::class);
            Route::apiResource('/skripsi-kp', SkripsiKpKaryawanController::class);
            Route::apiResource('/tunj-keluarga', ListTunjanganKeluargaKaryawanController::class);
            Route::apiResource('/tunjangan', TunjanganKaryawanController::class);

            // dataKaryawan
            Route::get('/profile/detail', [ProfileKaryawanController::class, 'allDataKaryawan']);
            Route::get('/profile/{id}', [ProfileKaryawanController::class, 'dataKaryawanBulanIniById']);
            Route::get('/profile/month/current', [ProfileKaryawanController::class, 'dataKaryawanBulanIni']);
            Route::apiResource('/profile', ProfileKaryawanController::class);
    });


    Route::apiResource('/karyawan/fungsional', FungsionalKaryawanController::class);

});

    
    

    // CEK NEW TEXT

// Route::get('/keuangan/mahasiswa', [KeuanganController::class, 'getAllMahasiswaAktif']);

// Route::controller(UserController::class)
//     ->prefix('/users')
//     ->middleware('auth.jwt')
//     ->group(function () {
//         Route::get('/me', 'getMyProfile');
//         Route::put('/me/password', 'putMyPassword');
//         Route::post('/me/image', 'addProfileImage');

//         // * route untuk admin
//         Route::get('/', 'getUserList')->middleware('auth.admin');
//         Route::post('/', 'addNewUser'); // buat awalan tambahin withoutMiddleware('auth.jwt')
//         Route::delete('/{id}', 'deleteUserById')->middleware('auth.admin');
//         Route::get('/v2/all', 'getUsersByRoles')->middleware('auth.admin');
//     });
