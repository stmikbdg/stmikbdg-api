<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arsip_digital.official_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('signer_user_id')->nullable();
            $table->string('signer_name_snapshot')->nullable();
            $table->string('signer_title_snapshot')->nullable();
        });

        $connection = DB::connection(config('myconfig.database.first_connection') ?: 'pgsql');
        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION arsip_digital.protect_official_document_signer_snapshot()
RETURNS trigger AS $$
BEGIN
    IF OLD.signer_user_id IS DISTINCT FROM NEW.signer_user_id
        OR OLD.signer_name_snapshot IS DISTINCT FROM NEW.signer_name_snapshot
        OR OLD.signer_title_snapshot IS DISTINCT FROM NEW.signer_title_snapshot THEN
        RAISE EXCEPTION 'Official document signer snapshot is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS protect_official_document_signer_snapshot ON arsip_digital.official_documents;
CREATE TRIGGER protect_official_document_signer_snapshot
BEFORE UPDATE ON arsip_digital.official_documents
FOR EACH ROW EXECUTE FUNCTION arsip_digital.protect_official_document_signer_snapshot();
SQL);
        }
    }

    public function down(): void
    {
        $connection = DB::connection(config('myconfig.database.first_connection') ?: 'pgsql');
        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS protect_official_document_signer_snapshot ON arsip_digital.official_documents;
DROP FUNCTION IF EXISTS arsip_digital.protect_official_document_signer_snapshot();
SQL);
        }

        Schema::table('arsip_digital.official_documents', function (Blueprint $table): void {
            $table->dropColumn([
                'signer_user_id',
                'signer_name_snapshot',
                'signer_title_snapshot',
            ]);
        });
    }
};
