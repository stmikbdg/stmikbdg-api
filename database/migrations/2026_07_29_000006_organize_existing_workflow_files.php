<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection(config('myconfig.database.first_connection') ?: 'pgsql');

        $connection->transaction(function () use ($connection): void {
            $this->migrateRequestFiles($connection);
            $this->migrateDistributionFiles($connection);
        }, 3);
    }

    private function migrateRequestFiles(ConnectionInterface $connection): void
    {
        $rows = $connection->table('arsip_digital.files as files')
            ->join('arsip_digital.request_files as request_files', 'request_files.file_id', '=', 'files.file_id')
            ->join('arsip_digital.requests as parents', 'parents.request_id', '=', 'request_files.request_id')
            ->join('arsip_digital.request_assignments as assignments', 'assignments.assignment_id', '=', 'request_files.assignment_id')
            ->leftJoin('arsip_digital.categories as current_categories', 'current_categories.category_id', '=', 'files.category_id')
            ->where('files.source_type', 'request')
            ->whereIn('request_files.submission_type', ['uploaded', 'admin_uploaded'])
            ->whereColumn('assignments.request_id', 'request_files.request_id')
            ->whereColumn('assignments.target_user_id', 'files.owner_user_id')
            ->whereColumn('assignments.target_role', 'files.owner_role')
            ->where(function ($query): void {
                $query->whereNull('files.category_id')
                    ->orWhere(function ($query): void {
                        $query->where('current_categories.is_system', false)
                            ->whereColumn('current_categories.owner_user_id', 'files.owner_user_id')
                            ->whereColumn('current_categories.owner_role', 'files.owner_role');
                    });
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('arsip_digital.signature_request_files as signature_files')
                    ->whereColumn('signature_files.result_file_id', 'files.file_id');
            })
            ->select(['files.file_id', 'files.owner_user_id', 'files.owner_role', 'parents.title', 'parents.created_by_user_id'])
            ->get();

        $this->moveUnambiguousFiles($connection, $rows);
    }

    private function migrateDistributionFiles(ConnectionInterface $connection): void
    {
        $rows = $connection->table('arsip_digital.files as files')
            ->join('arsip_digital.distribution_recipients as recipients', 'recipients.file_id', '=', 'files.file_id')
            ->join('arsip_digital.distributions as parents', 'parents.distribution_id', '=', 'recipients.distribution_id')
            ->leftJoin('arsip_digital.categories as current_categories', 'current_categories.category_id', '=', 'files.category_id')
            ->where('files.source_type', 'distribution')
            ->whereColumn('recipients.target_user_id', 'files.owner_user_id')
            ->whereColumn('recipients.target_role', 'files.owner_role')
            ->where(function ($query): void {
                $query->whereNull('files.category_id')
                    ->orWhere(function ($query): void {
                        $query->where('current_categories.is_system', false)
                            ->whereColumn('current_categories.owner_user_id', 'files.owner_user_id')
                            ->whereColumn('current_categories.owner_role', 'files.owner_role');
                    });
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('arsip_digital.signature_request_files as signature_files')
                    ->whereColumn('signature_files.result_file_id', 'files.file_id');
            })
            ->select(['files.file_id', 'files.owner_user_id', 'files.owner_role', 'parents.title', 'parents.created_by_user_id'])
            ->get();

        $this->moveUnambiguousFiles($connection, $rows);
    }

    private function moveUnambiguousFiles(ConnectionInterface $connection, $rows): void
    {
        foreach ($rows->groupBy('file_id') as $parents) {
            if ($parents->count() !== 1) {
                continue;
            }

            $file = $parents->first();
            $name = trim((string) $file->title);

            if ($name === '') {
                continue;
            }

            $categoryId = $connection->table('arsip_digital.categories')
                ->where('owner_user_id', $file->owner_user_id)
                ->where('owner_role', $file->owner_role)
                ->where('category_type', 'personal')
                ->where('name', $name)
                ->where('is_system', true)
                ->whereNull('deleted_at')
                ->value('category_id');

            if (! $categoryId) {
                $categoryId = $connection->table('arsip_digital.categories')->insertGetId([
                    'owner_user_id' => $file->owner_user_id,
                    'owner_role' => $file->owner_role,
                    'category_type' => 'personal',
                    'name' => $name,
                    'visibility' => 'admin_visible',
                    'created_by_user_id' => $file->created_by_user_id,
                    'created_by_role' => 'admin',
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'category_id');
            }

            $connection->table('arsip_digital.files')
                ->where('file_id', $file->file_id)
                ->update(['category_id' => $categoryId]);
        }
    }

    public function down(): void {}
};
