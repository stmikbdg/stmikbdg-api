<?php

use App\Http\Controllers\Kuliah\RekapPresensiController;
use App\Http\Controllers\TahunAjaranController;
use Illuminate\Support\Facades\Route;

Route::prefix('rekap')
    ->middleware('auth.jwt')
    ->group(function () {
        // rekap presensi
        Route::prefix('presensi')
            ->middleware('auth.prodi')
            ->group(function () {
                // filter
                Route::prefix('filter')
                    ->group(function () {
                        Route::get('/tahun-ajaran', [TahunAjaranController::class, 'getTahunAjaranAktifV2']);

                        Route::controller(RekapPresensiController::class)
                            ->group(function () {
                                Route::get('/dosen', 'getListDosen');
                                Route::get('/matkul', 'getListMatkul');
                            });
                    });

                Route::controller(RekapPresensiController::class)
                    ->group(function () {
                        Route::get('', 'getRekapPresensi');
                        Route::get('/export', 'exportPresensi');
                    });
            });

        // rekap pertemuan
        Route::prefix('pertemuan')
            ->middleware('auth.admin')
            ->group(function () {
                Route::get('', [RekapPresensiController::class, 'getRekapPertemuan']);
                Route::prefix('/v2')
                    ->group(function () {
                        Route::get('/', [RekapPresensiController::class, 'getRekapPertemuanV2']);
                    });
            });

        Route::prefix('berita-acara')
            ->controller(RekapPresensiController::class)
            ->group(function () {

                // Admin
                Route::middleware('auth.prodi')
                    ->group(function () {
                        Route::get('', 'getRekapBeritaAcara');
                        Route::get('/export', 'exportBeritaAcara');
                    });

                Route::middleware('auth.dosen')
                    ->prefix('dosen')
                    ->group(function () {

                        Route::prefix('filter')
                            ->group(function () {
                                Route::get('/matkul/{tahunId}', 'getFilterBAPDosenByMatkul');
                            });

                        Route::get('/kelas-kuliah/{kelas_kuliah_id}', 'getBAPDosenByKelasKuliahId');
                    });

            });
    });
