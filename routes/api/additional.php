<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TahunAjaranController;

/**
 * Daftar routes yang ada di sini digunakan hanya sebagai tambahan
 * dengan harapan dapat mempermudah beberapa keterbacaan dan akses ke sumber daya tertentu
 */

// ? Get Current Semester
Route::get('/current-semester', [TahunAjaranController::class, 'getSemesterMahasiswaSekarang'])
    ->middleware(['auth.jwt', 'auth.mahasiswa']);

Route::get('/all/tahun-ajaran-all', [TahunAjaranController::class, 'getTahunAjaranAktifNoFilter']);