<?php

use App\Http\Controllers\Ujian\MataKuliahUjianController;
use App\Http\Controllers\Ujian\MigrationsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')
    ->prefix('/ujian')
    ->group(function () {
        
        Route::controller(MataKuliahUjianController::class)
            ->group(function () {
                Route::get('/kelas-kuliah', 'getAll_kelas_kuliah')
                    ->middleware('auth.admin');
                Route::prefix('/mata-kuliah')
                    ->group(function () {
                        
                        // Mahasiswa
                        Route::prefix('/mahasiswa')
                            ->middleware('auth.mahasiswa')
                            ->group(function () {
                                Route::get('/', 'getAll_mata_kuliah_mahasiswa');
                        });

                        // Dosen
                        Route::prefix('/dosen')
                            ->middleware('auth.dosen')
                            ->group(function () {
                                Route::get('/', 'getAll_mata_kuliah_dosen');
                                Route::get('/mk_id/{mk_id}', 'get_mata_kuliah_by_mk_id');
                                Route::get('/count-mahasiswa/kelas-kuliah-id/{kelas_kuliah_id}', 'count_mahasiswa_by_kelas_kuliah_id');
                        });
                });
        });

        Route::controller(MigrationsController::class)
            ->middleware('auth.admin')
            ->prefix('/migrations')
            ->group(function () {
                
                Route::prefix('/users')
                    ->group(function () {
                        Route::get('/mahasiswa', 'migrate_mahasiswa');
                        Route::get('/dosen', 'migrate_dosen');
                        Route::get('/admin', 'migrate_admin');
                        Route::get('/prodi', 'migrate_prodi');        
                    });

                Route::get('/matakuliah', 'migrate_matakuliah');
        });
    });