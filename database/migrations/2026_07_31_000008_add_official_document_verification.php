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
            $table->string('verification_token_hash', 64)->unique()->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->text('revocation_reason')->nullable();
        });

        $connection = DB::connection(config('myconfig.database.first_connection') ?: 'pgsql');
        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION arsip_digital.protect_official_document_verification_token()
RETURNS trigger AS $$
BEGIN
    IF OLD.verification_token_hash IS DISTINCT FROM NEW.verification_token_hash THEN
        RAISE EXCEPTION 'Official document verification token is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS protect_official_document_verification_token ON arsip_digital.official_documents;
CREATE TRIGGER protect_official_document_verification_token
BEFORE UPDATE ON arsip_digital.official_documents
FOR EACH ROW EXECUTE FUNCTION arsip_digital.protect_official_document_verification_token();
SQL);
        }
    }

    public function down(): void
    {
        $connection = DB::connection(config('myconfig.database.first_connection') ?: 'pgsql');
        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS protect_official_document_verification_token ON arsip_digital.official_documents;
DROP FUNCTION IF EXISTS arsip_digital.protect_official_document_verification_token();
SQL);
        }

        Schema::table('arsip_digital.official_documents', function (Blueprint $table): void {
            $table->dropUnique(['verification_token_hash']);
            $table->dropColumn([
                'verification_token_hash',
                'revoked_at',
                'revoked_by_user_id',
                'revocation_reason',
            ]);
        });
    }
};
