<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\AuditLog;
use App\Models\ArsipDigital\Distribution;
use App\Models\ArsipDigital\DistributionRecipient;
use App\Models\ArsipDigital\Notification;
use App\Models\ArsipDigital\OfficialDocument;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OfficialDocumentIssuanceService
{
    public function __construct(
        private readonly AcademicDocumentDataService $academicData,
        private readonly OfficialDocumentPdfService $pdf,
        private readonly ArsipDigitalStorageService $storage,
        private readonly ArchiveCategoryService $categories,
        private readonly TargetResolverService $targets,
        private readonly OfficialDocumentTokenService $tokens,
        private readonly OfficialDocumentSignerService $signers,
    ) {}

    public function issue(array $payload, object $actor, string $actorRole, Request $request): OfficialDocument
    {
        $documentType = $payload['document_type'];
        $semester = $documentType === 'khs' ? (int) $payload['semester'] : null;
        $documentNumber = trim($payload['document_number']);
        $replacesDocumentId = isset($payload['replaces_document_id']) ? (int) $payload['replaces_document_id'] : null;
        $replacementReason = isset($payload['replacement_reason']) ? trim($payload['replacement_reason']) : null;
        $snapshot = $this->academicData->documentForStudent(
            (int) $payload['mhs_id'],
            $documentType,
            $semester
        );
        $target = $this->targets->resolve('mahasiswa', (string) $snapshot['student']['nim']);
        $signer = $this->signers->snapshot((int) $payload['signer_user_id'], $payload['signer_title']);

        if (! $target['valid']) {
            throw new HttpException(422, $target['error']);
        }

        $verificationToken = $this->tokens->generate();
        $verificationUrl = rtrim((string) config('app.url'), '/').'/api/arsip-digital/verify/'.$verificationToken;
        $pdfBytes = $this->pdf->render($documentType, $documentNumber, $snapshot, $semester, $verificationUrl, false, $signer);
        $filename = $this->pdf->filename($documentType, $target['identifier'], $semester);
        $stored = $this->storage->uploadPrivateBytes($pdfBytes, $filename, 'official', [
            'document_type' => $documentType,
            'owner_user_id' => $target['target_user_id'],
        ]);

        try {
            return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($actor, $actorRole, $documentNumber, $documentType, $semester, $snapshot, $stored, $target, $signer, $request, $verificationToken, $replacesDocumentId, $replacementReason): OfficialDocument {
                if (OfficialDocument::where('document_number', $documentNumber)->lockForUpdate()->exists()) {
                    throw new HttpException(409, 'Nomor dokumen resmi sudah digunakan.');
                }

                $replacedDocument = $replacesDocumentId
                    ? OfficialDocument::whereKey($replacesDocumentId)->lockForUpdate()->firstOrFail()
                    : null;
                if ($replacedDocument && (
                    $replacedDocument->status !== 'issued'
                    || $replacedDocument->document_type !== $documentType
                    || $replacedDocument->subject_user_id !== $target['target_user_id']
                    || $replacedDocument->semester !== $semester
                )) {
                    throw new HttpException(409, 'Dokumen pengganti harus memiliki pemilik dan jenis yang sama serta masih aktif.');
                }

                $category = $this->categories->systemPersonalCategory(
                    $target['target_user_id'],
                    'mahasiswa',
                    'Dokumen Akademik Resmi',
                    $actor,
                    $actorRole
                );

                $file = ArchiveFile::create([
                    'category_id' => $category->category_id,
                    'owner_user_id' => $target['target_user_id'],
                    'owner_role' => 'mahasiswa',
                    'owner_identifier' => $target['identifier'],
                    'owner_name_snapshot' => $target['name_snapshot'],
                    'owner_status_snapshot' => $target['status_snapshot'],
                    'uploaded_by_user_id' => $actor->id,
                    'uploaded_by_role' => $actorRole,
                    'source_type' => 'official',
                    'original_filename' => $stored['original_filename'],
                    'display_filename' => $stored['display_filename'],
                    'storage_disk' => $stored['storage_disk'],
                    'storage_path' => $stored['storage_path'],
                    'mime_type' => $stored['mime_type'],
                    'extension' => $stored['extension'],
                    'file_size_bytes' => $stored['file_size_bytes'],
                    'checksum_sha256' => $stored['checksum_sha256'],
                    'version_group_uuid' => (string) Str::uuid(),
                    'version_number' => 1,
                    'is_current' => true,
                    'status' => 'active',
                    'storage_availability' => 'available',
                    'metadata' => [
                        'document_type' => $documentType,
                        'document_number' => $documentNumber,
                        'template_version' => OfficialDocumentPdfService::TEMPLATE_VERSION,
                    ],
                ]);

                $issuedAt = now();
                $document = OfficialDocument::create([
                    'document_type' => $documentType,
                    'document_number' => $documentNumber,
                    'semester' => $semester,
                    'subject_user_id' => $target['target_user_id'],
                    'subject_mhs_id' => $snapshot['student']['mhs_id'],
                    'subject_identifier' => $target['identifier'],
                    'subject_name_snapshot' => $target['name_snapshot'],
                    'academic_snapshot' => $snapshot,
                    'snapshot_captured_at' => $issuedAt,
                    'template_version' => OfficialDocumentPdfService::TEMPLATE_VERSION,
                    'file_id' => $file->file_id,
                    'file_checksum_sha256' => $stored['checksum_sha256'],
                    'verification_token_hash' => hash('sha256', $verificationToken),
                    'status' => 'issued',
                    'issued_by_user_id' => $actor->id,
                    'signer_user_id' => $signer['user_id'],
                    'signer_name_snapshot' => $signer['name'],
                    'signer_title_snapshot' => $signer['title'],
                    'issued_at' => $issuedAt,
                ]);

                if ($replacedDocument) {
                    $replacedAt = now();
                    $replacedDocument->update([
                        'status' => 'replaced',
                        'replaced_by_document_id' => $document->official_document_id,
                        'replaced_at' => $replacedAt,
                        'replacement_reason' => $replacementReason,
                    ]);
                    ArchiveFile::whereKey($replacedDocument->file_id)->update([
                        'status' => 'replaced',
                        'is_current' => false,
                        'updated_at' => $replacedAt,
                    ]);
                    $oldDistribution = Distribution::where('official_document_id', $replacedDocument->official_document_id)->lockForUpdate()->first();
                    if ($oldDistribution && $oldDistribution->status === 'published') {
                        $oldDistribution->update([
                            'status' => 'closed',
                            'withdrawn_at' => $replacedAt,
                            'withdrawn_by_user_id' => $actor->id,
                            'withdrawal_reason' => $replacementReason,
                        ]);
                        DistributionRecipient::where('distribution_id', $oldDistribution->distribution_id)->update([
                            'delivery_status' => 'revoked',
                            'updated_at' => $replacedAt,
                        ]);
                    }
                    Notification::create([
                        'recipient_user_id' => $replacedDocument->subject_user_id,
                        'recipient_role' => 'mahasiswa',
                        'type' => 'official_document_replaced',
                        'title' => 'Dokumen akademik resmi diganti',
                        'message' => 'Dokumen '.$replacedDocument->document_number.' telah diganti oleh '.$document->document_number.'.',
                        'entity_type' => 'official_document',
                        'entity_id' => $replacedDocument->official_document_id,
                        'data' => [
                            'official_document_id' => $replacedDocument->official_document_id,
                            'replaced_by_document_id' => $document->official_document_id,
                            'reason' => $replacementReason,
                        ],
                    ]);
                    AuditLog::create([
                        'actor_user_id' => $actor->id,
                        'actor_role' => $actorRole,
                        'action' => 'official_document.replaced',
                        'entity_type' => 'official_document',
                        'entity_id' => (string) $replacedDocument->official_document_id,
                        'description' => 'Dokumen akademik resmi diganti.',
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'metadata' => [
                            'replaced_by_document_id' => $document->official_document_id,
                            'reason' => $replacementReason,
                        ],
                        'created_at' => $replacedAt,
                    ]);
                }

                $audit = AuditLog::create([
                    'actor_user_id' => $actor->id,
                    'actor_role' => $actorRole,
                    'action' => 'official_document.issued',
                    'entity_type' => 'official_document',
                    'entity_id' => (string) $document->official_document_id,
                    'description' => 'Dokumen akademik resmi diterbitkan.',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => [
                        'document_type' => $documentType,
                        'document_number' => $documentNumber,
                        'subject_identifier' => $target['identifier'],
                        'file_id' => $file->file_id,
                        'checksum_sha256' => $stored['checksum_sha256'],
                        'template_version' => OfficialDocumentPdfService::TEMPLATE_VERSION,
                    ],
                    'created_at' => $issuedAt,
                ]);

                if (! $audit->exists) {
                    throw new HttpException(500, 'Audit penerbitan dokumen gagal disimpan.');
                }

                return $document->load('file');
            }, 3);
        } catch (QueryException $e) {
            $this->storage->deletePrivate($stored['storage_disk'], $stored['storage_path']);

            if (str_contains(strtolower($e->getMessage()), 'document_number')) {
                throw new HttpException(409, 'Nomor dokumen resmi sudah digunakan.', $e);
            }

            throw $e;
        } catch (\Throwable $e) {
            $this->storage->deletePrivate($stored['storage_disk'], $stored['storage_path']);
            throw $e;
        }
    }

    public function revoke(OfficialDocument $document, string $reason, object $actor, string $actorRole, Request $request): OfficialDocument
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($document, $reason, $actor, $actorRole, $request): OfficialDocument {
            $locked = OfficialDocument::whereKey($document->official_document_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'issued') {
                throw new HttpException(409, 'Dokumen resmi sudah tidak aktif.');
            }

            $revokedAt = now();
            $locked->fill([
                'status' => 'revoked',
                'revoked_at' => $revokedAt,
                'revoked_by_user_id' => $actor->id,
                'revocation_reason' => trim($reason),
            ])->save();

            ArchiveFile::whereKey($locked->file_id)->update([
                'status' => 'revoked',
                'is_current' => false,
                'updated_at' => $revokedAt,
            ]);

            $distribution = Distribution::where('official_document_id', $locked->official_document_id)->lockForUpdate()->first();
            if ($distribution && $distribution->status === 'published') {
                $distribution->update([
                    'status' => 'closed',
                    'withdrawn_at' => $revokedAt,
                    'withdrawn_by_user_id' => $actor->id,
                    'withdrawal_reason' => trim($reason),
                ]);
                DistributionRecipient::where('distribution_id', $distribution->distribution_id)->update([
                    'delivery_status' => 'revoked',
                    'updated_at' => $revokedAt,
                ]);
                Notification::create([
                    'recipient_user_id' => $locked->subject_user_id,
                    'recipient_role' => 'mahasiswa',
                    'type' => 'official_document_revoked',
                    'title' => 'Dokumen akademik resmi dicabut',
                    'message' => 'Dokumen '.$locked->document_number.' dicabut: '.trim($reason),
                    'entity_type' => 'official_document',
                    'entity_id' => $locked->official_document_id,
                    'data' => ['official_document_id' => $locked->official_document_id, 'reason' => trim($reason)],
                ]);
            }

            AuditLog::create([
                'actor_user_id' => $actor->id,
                'actor_role' => $actorRole,
                'action' => 'official_document.revoked',
                'entity_type' => 'official_document',
                'entity_id' => (string) $locked->official_document_id,
                'description' => 'Dokumen akademik resmi dicabut.',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'document_number' => $locked->document_number,
                    'file_id' => $locked->file_id,
                    'reason' => trim($reason),
                ],
                'created_at' => $revokedAt,
            ]);

            return $locked->fresh('file');
        }, 3);
    }
}
