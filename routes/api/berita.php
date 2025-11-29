<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TahunAjaranController;


/**
 * List route yang ada di sini digunakan untuk sistem kuesioner
 * jadi prefix atau awalan dari path dibuat bernama /kuesioner
 */


// ? Kuesioner Routes
Route::prefix('/berita')
    ->middleware('auth.jwt')
    ->group(function () {

        // admin
        Route::middleware('auth.admin')
            ->group(function () {
                Route::get('/tahun-ajaran', [TahunAjaranController::class, 'getTahunAjaranAktifForBerita']);

            });
    });
