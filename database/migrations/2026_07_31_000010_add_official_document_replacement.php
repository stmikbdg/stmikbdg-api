<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arsip_digital.official_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('replaced_by_document_id')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->text('replacement_reason')->nullable();
            $table->foreign('replaced_by_document_id')
                ->references('official_document_id')
                ->on('arsip_digital.official_documents');
        });
    }

    public function down(): void
    {
        Schema::table('arsip_digital.official_documents', function (Blueprint $table): void {
            $table->dropForeign(['replaced_by_document_id']);
            $table->dropColumn([
                'replaced_by_document_id',
                'replaced_at',
                'replacement_reason',
            ]);
        });
    }
};
