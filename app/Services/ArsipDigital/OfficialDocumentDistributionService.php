<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\AuditLog;
use App\Models\ArsipDigital\Distribution;
use App\Models\ArsipDigital\DistributionRecipient;
use App\Models\ArsipDigital\Notification;
use App\Models\ArsipDigital\OfficialDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OfficialDocumentDistributionService
{
    public function distribute(OfficialDocument $document, object $actor, string $actorRole, Request $request): Distribution
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($document, $actor, $actorRole, $request): Distribution {
            $document = OfficialDocument::with('file')->whereKey($document->official_document_id)->lockForUpdate()->firstOrFail();

            if ($document->status !== 'issued' || ! $document->file || $document->file->status !== 'active') {
                throw new HttpException(409, 'Hanya dokumen resmi aktif yang dapat didistribusikan.');
            }

            if (Distribution::where('official_document_id', $document->official_document_id)->lockForUpdate()->exists()) {
                throw new HttpException(409, 'Dokumen resmi sudah didistribusikan.');
            }

            $publishedAt = now();
            $distribution = Distribution::create([
                'official_document_id' => $document->official_document_id,
                'title' => $this->title($document),
                'description' => 'Dokumen akademik resmi nomor '.$document->document_number.'.',
                'target_role' => 'mahasiswa',
                'scope_type' => 'specific',
                'target_identifiers' => [$document->subject_identifier],
                'status' => 'published',
                'created_by_user_id' => $actor->id,
                'published_at' => $publishedAt,
            ]);

            $student = $document->academic_snapshot['student'] ?? [];
            $recipient = DistributionRecipient::create([
                'distribution_id' => $distribution->distribution_id,
                'target_user_id' => $document->subject_user_id,
                'target_role' => 'mahasiswa',
                'identifier' => $document->subject_identifier,
                'name_snapshot' => $document->subject_name_snapshot,
                'angkatan_snapshot' => $student['angkatan'] ?? null,
                'prodi_snapshot' => $student['prodi'] ?? null,
                'status_snapshot' => $student['status'] ?? null,
                'metadata' => [
                    'official_document_id' => $document->official_document_id,
                    'document_number' => $document->document_number,
                ],
                'file_id' => $document->file_id,
                'delivery_status' => 'available',
            ]);

            Notification::create([
                'recipient_user_id' => $document->subject_user_id,
                'recipient_role' => 'mahasiswa',
                'type' => 'official_document_available',
                'title' => 'Dokumen akademik resmi tersedia',
                'message' => $distribution->title.' sudah tersedia untuk diunduh.',
                'entity_type' => 'distribution_recipient',
                'entity_id' => $recipient->recipient_id,
                'data' => [
                    'distribution_id' => $distribution->distribution_id,
                    'recipient_id' => $recipient->recipient_id,
                    'file_id' => $document->file_id,
                    'official_document_id' => $document->official_document_id,
                ],
            ]);

            AuditLog::create([
                'actor_user_id' => $actor->id,
                'actor_role' => $actorRole,
                'action' => 'official_document.distributed',
                'entity_type' => 'official_document',
                'entity_id' => (string) $document->official_document_id,
                'description' => 'Dokumen akademik resmi didistribusikan.',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'distribution_id' => $distribution->distribution_id,
                    'recipient_id' => $recipient->recipient_id,
                    'file_id' => $document->file_id,
                    'subject_identifier' => $document->subject_identifier,
                ],
                'created_at' => $publishedAt,
            ]);

            return $distribution->load(['recipients.file', 'officialDocument']);
        }, 3);
    }

    private function title(OfficialDocument $document): string
    {
        $type = $document->document_type === 'khs'
            ? 'KHS Semester '.$document->semester
            : 'Transkrip Nilai';

        return $type.' — '.$document->document_number;
    }
}
