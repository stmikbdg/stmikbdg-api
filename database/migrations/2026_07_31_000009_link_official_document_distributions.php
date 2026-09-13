<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arsip_digital.distributions', function (Blueprint $table): void {
            $table->unsignedBigInteger('official_document_id')->nullable()->unique();
            $table->foreign('official_document_id')
                ->references('official_document_id')
                ->on('arsip_digital.official_documents');
        });
    }

    public function down(): void
    {
        Schema::table('arsip_digital.distributions', function (Blueprint $table): void {
            $table->dropForeign(['official_document_id']);
            $table->dropUnique(['official_document_id']);
            $table->dropColumn('official_document_id');
        });
    }
};
