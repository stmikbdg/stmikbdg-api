<?php

use App\Http\Controllers\Sidang\PengajuanController;
use Illuminate\Support\Facades\Route;

/**
 * Routes yang ada di sini digunakan pada SIKPS v2.
 * Sistem Deteksi Proposal Skripsi
 */

// ? Get all data skripsi mahasiswa
Route::prefix('/sikpsv2')
    ->middleware('auth.jwt')
    ->group(function () {
        // admin
        Route::controller(PengajuanController::class)
            ->prefix('/pengajuan')
            // ->middleware('auth.admin')
            ->group(function () {
                Route::get('/all', 'getAllPengajuan');
                Route::post('/tambah','KirimPengajuan')->middleware('auth.admin');
            });

  });
