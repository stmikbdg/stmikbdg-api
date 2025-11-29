<?php

use App\Http\Controllers\SIKPS\DataKpSkripsiController;
use App\Http\Controllers\SIKPS\DeteksiProposalController;
use App\Http\Controllers\SIKPS\DospemPembimbingMahasiswaController;
use App\Http\Controllers\SIKPS\MahasiswaDeteksiProposalController;
use App\Http\Controllers\SIKPS\MasterAbsensiController;
use App\Http\Controllers\SIKPS\MasterBimbinganController;
use App\Http\Controllers\SIKPS\MasterJadwalController;
use App\Http\Controllers\SIKPS\MasterTahunAkademikController;
use App\Models\SIKPS\DospemPembimbingMahasiswa;
use Illuminate\Support\Facades\Route;

/**
 * Routes yang ada di sini digunakan pada SIKPS.
 * Sistem Deteksi Proposal Skripsi
 */

// ? Get all data skripsi mahasiswa
Route::prefix('/sikps')
    ->middleware('auth.jwt')
    ->group(function () {
        // admin
        Route::controller(DeteksiProposalController::class)
            ->prefix('/deteksi')
            ->middleware('auth.admin')
            ->group(function () {
                Route::post('/fingerprints/add', 'addFingerprints');
                Route::get('/fingerprints/list', 'getAllFingerprints');
                Route::delete('/fingerprints/delete', 'deleteFingerprint');
                Route::put('/fingerprints/update', 'updateFingerprint');
                Route::get('/fingerprints/detail', 'getDetail');
                Route::get('/riwayat', 'getAllRiwayatDeteksi');
                Route::delete('/fingerprints/generated/delete', 'deleteAllGeneratedFingerprints');
                Route::put('/similarities/limit/update', 'updateSimilarityLimit');
                Route::get('/similarities/limit', 'getSimilarityLimit')
                    ->withoutMiddleware('auth.admin');
            });

        Route::controller(DataKpSkripsiController::class)
            ->group(function () {

                Route::post('/import/data-kp-skripsi', 'import');

                Route::prefix('/data-kp-skripsi')
                    ->group(function () {
                        Route::get('/', 'getAll');
                        Route::get('/mahasiswa', 'getForMahasiswa');
                        Route::get('/mahasiswa/{jenis}', 'getForMahasiswaByJenis');
                        Route::get('/nim/{nim}', 'getByNim');
                        Route::get('/dospem', 'getForDospem');
                        
                        Route::post('/', 'create');
                        Route::put('/{id}', 'update');
                        Route::delete('/{id}', 'delete');
                    });
                
            });

        Route::controller(MasterJadwalController::class)
            ->group(function () {
                Route::prefix('/master-jadwal')
                    ->group(function () {
                        Route::middleware('auth.dospem')
                            ->group(function () {
                                Route::get('/', 'getAll');
                                Route::post('/', 'create');
                                Route::put('/id/{id}', 'update');
                                Route::delete('/id/{id}', 'delete');
                            });
                        
                        Route::middleware('auth.mahasiswa')
                            ->prefix('/mahasiswa')
                            ->group(function () {
                                Route::get('/', 'getAll_mahasiswa');
                            });
                    });
            });

        Route::controller(MasterBimbinganController::class)
            ->group(function () {
                Route::prefix('/master-bimbingan')
                    ->group(function () {

                        Route::prefix('/admin')
                            ->middleware('auth.admin')
                            ->group(function () {
                                Route::get('/', 'getAll_admin');
                                Route::post('/', 'create_admin');
                                // Route::put('/id/{id}', 'update_admin');
                                Route::post('/update/id/{id}', 'update_admin');
                                Route::delete('/id/{id}', 'delete_admin');
                                Route::put('/arsip', 'arsip_admin');
                            });

                        Route::prefix('/dospem')
                            ->middleware('auth.dospem')
                            ->group(function () {
                                Route::get('/', 'getAll_dospem');
                                // Route::post('/', 'create_dospem');
                                // Route::put('/id/{id}', 'update_dospem');
                                Route::delete('/id/{id}', 'delete_dospem');
                                Route::get('/review/id/{id}', 'review_dospem');
                                // Route::post('/feedback/id/{id}', 'feedback_dospem');
                                Route::put('/arsip', 'arsip_dospem');
                            });

                        Route::prefix('/mahasiswa')
                            ->middleware('auth.mahasiswa')
                            ->group(function () {
                                Route::get('/', 'getAll_mahasiswa');
                                Route::post('/', 'create_mahasiswa');
                                Route::put('/id/{id}', 'update_mahasiswa');
                                Route::delete('/id/{id}', 'delete_mahasiswa');
                            });
                    });
            });

        Route::controller(MasterAbsensiController::class)
            ->prefix('/master-absensi')
            ->group(function () {
                
                Route::prefix('/mahasiswa')
                    ->middleware('auth.mahasiswa')
                    ->group(function () {
                        Route::get('/', 'getAll_mahasiswa'); 
                        Route::post('/booking/jadwal_id/{jadwal_id}', 'booking_mahasiswa');
                        Route::delete('/booking/id/{id}', 'delete_booking_mahasiswa');
                    });

                Route::prefix('/dospem')
                    ->middleware('auth.dospem')
                    ->group(function () {
                        Route::get('/', 'getAll_dospem');
                        Route::post('/feedback/id/{id}', 'feedback_dospem');
                        Route::delete('/booking/id/{id}', 'cancel_booking_dospem');
                        // Route::put('/id/{id}', 'update_single_dospem');
                        // Route::put('/multi-id', 'update_multi_dospem');
                    });

            });

        // mahasiswa
        Route::controller(MahasiswaDeteksiProposalController::class)
            ->prefix('/mahasiswa')
            ->middleware('auth.mahasiswa')
            ->group(function () {
                Route::prefix('/deteksi')
                    ->group(function () {
                        Route::post('/hasil/add', 'addHasilDeteksi');
                        Route::get('/hasil/list', 'getListHasilDeteksi');
                        Route::put('/hasil/update', 'updateProposal');
                        Route::delete('/hasil/delete', 'deleteProposal');
                        Route::get('/hasil/detail', 'getDetail');
                        Route::get('/fingerprints/list', 'getAllFingerprints');
                    });
            });
    });
