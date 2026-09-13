<?php

use App\Http\Controllers\ArsipDigital\InstitutionalArchiveVerificationController;
use App\Http\Controllers\ArsipDigital\OfficialDocumentVerificationController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/arsip-digital/verify/{token}', [OfficialDocumentVerificationController::class, 'show'])
    ->where('token', '[A-Fa-f0-9]{64}');
Route::get('/arsip-digital/institutional-verify/{token}', [InstitutionalArchiveVerificationController::class, 'show'])
    ->where('token', '[A-Fa-f0-9]{64}');

// ? Authentications Routes
require __DIR__.'/api/authentications.php';

// ? Users Routes
require __DIR__.'/api/users.php';

// ? KRS Routes
require __DIR__.'/api/krs.php';

// ? Kelas Kuliah Routes
require __DIR__.'/api/kuliah.php';

// ? Kuesioner Routes
require __DIR__.'/api/kuesioner.php';

// ? SIKPS Routes
require __DIR__.'/api/sikps.php';

// ? Pengajuan Wisuda Routes
require __DIR__.'/api/wisuda.php';

// ? Antrian
require __DIR__.'/api/antrian.php';

// ? Pengumuman
require __DIR__.'/api/pengumuman.php';

// ? Keuangan
require __DIR__.'/api/keuangan.php';

// ? Surat
require __DIR__.'/api/surat.php';

// ? ACL Routes
require __DIR__.'/api/acl.php';

// ? Verdig Routes
require __DIR__.'/api/verdig.php';

// ? Rekap
require __DIR__.'/api/rekap.php';

// ? Berita Acara
require __DIR__.'/api/berita.php';

// ? SIKPS V2
require __DIR__.'/api/sikpsv2.php';

// ? Ujian
require __DIR__.'/api/ujian.php';

// ? Additional Routes (Tambahan)
require __DIR__.'/api/additional.php';
require __DIR__.'/api/file.php';

// ? Arsip Digital
require __DIR__.'/api/arsip-digital.php';
