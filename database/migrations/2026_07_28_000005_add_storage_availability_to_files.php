<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arsip_digital.files', function (Blueprint $table): void {
            $table->string('storage_availability')->default('unknown')->index();
        });
    }

    public function down(): void
    {
        Schema::table('arsip_digital.files', function (Blueprint $table): void {
            $table->dropColumn('storage_availability');
        });
    }
};
