<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection(config('myconfig.database.first_connection'));

        $connection->transaction(function () use ($connection): void {
            $results = $connection->table('arsip_digital.signature_request_files as request_files')
                ->join('arsip_digital.signature_requests as requests', 'requests.signature_request_id', '=', 'request_files.signature_request_id')
                ->join('arsip_digital.files as files', 'files.file_id', '=', 'request_files.result_file_id')
                ->whereNotNull('request_files.result_file_id')
                ->select([
                    'requests.signature_request_id',
                    'requests.student_user_id',
                    'requests.lecturer_user_id',
                    'requests.lecturer_name',
                    'files.file_id',
                    'files.metadata',
                ])
                ->get();

            foreach ($results as $result) {
                $folderName = 'request_ttd_'.(Str::slug(trim((string) $result->lecturer_name), '_') ?: 'dosen');
                $categoryId = $connection->table('arsip_digital.categories')
                    ->where('owner_user_id', $result->student_user_id)
                    ->where('owner_role', 'mahasiswa')
                    ->where('category_type', 'personal')
                    ->where('name', $folderName)
                    ->value('category_id');

                if (! $categoryId) {
                    $categoryId = $connection->table('arsip_digital.categories')->insertGetId([
                        'owner_user_id' => $result->student_user_id,
                        'owner_role' => 'mahasiswa',
                        'category_type' => 'personal',
                        'name' => $folderName,
                        'visibility' => 'admin_visible',
                        'created_by_user_id' => $result->lecturer_user_id,
                        'created_by_role' => 'dosen',
                        'is_system' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], 'category_id');
                }

                $metadata = is_array($result->metadata) ? $result->metadata : json_decode((string) $result->metadata, true);
                $metadata = array_merge($metadata ?: [], [
                    'archive_type' => 'Permintaan berkas tanda tangan',
                    'display_type' => 'Permintaan berkas tanda tangan',
                    'folder' => $folderName,
                    'signature_request_id' => $result->signature_request_id,
                ]);

                $connection->table('arsip_digital.files')->where('file_id', $result->file_id)->update([
                    'category_id' => $categoryId,
                    'source_type' => 'request',
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            }
        }, 3);
    }

    public function down(): void {}
};
