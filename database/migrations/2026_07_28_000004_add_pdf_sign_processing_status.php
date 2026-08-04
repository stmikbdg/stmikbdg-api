<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('myconfig.database.first_connection'))->table('arsip_digital.pdf_sign_sessions', function (Blueprint $table) {
            $table->string('storage_disk')->default('local');
            $table->string('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
        });
        if (DB::connection(config('myconfig.database.first_connection'))->getDriverName() === 'pgsql') {
            DB::connection(config('myconfig.database.first_connection'))->statement('ALTER TABLE arsip_digital.pdf_sign_sessions DROP CONSTRAINT IF EXISTS pdf_sign_sessions_status_check');
            DB::connection(config('myconfig.database.first_connection'))->statement("ALTER TABLE arsip_digital.pdf_sign_sessions ADD CONSTRAINT pdf_sign_sessions_status_check CHECK (status IN ('created', 'queued', 'processing', 'finalized', 'failed', 'saved'))");
        }
    }

    public function down(): void
    {
        Schema::connection(config('myconfig.database.first_connection'))->table('arsip_digital.pdf_sign_sessions', function (Blueprint $table) {
            $table->dropColumn(['storage_disk', 'error_message', 'started_at', 'finished_at']);
        });
    }
};
