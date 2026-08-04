<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\PdfSignSession;
use App\Models\ArsipDigital\SignatureRequest;
use App\Models\ArsipDigital\SignatureRequestFile;
use App\Models\Users\Dosen;
use App\Models\Users\MahasiswaView;
use App\Models\Users\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SignatureRequestService
{
    public function __construct(private ArsipDigitalSettingsService $settings, private NotificationService $notifications, private PdfSelfSignService $signer, private ArchiveFileService $archives, private AuditLogService $audit) {}

    public function create(object $student, array $data): SignatureRequest
    {
        $files = $this->validateFiles($student, $data['file_ids']);
        $settings = $this->settings->getDefaults();

        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($student, $data, $files, $settings) {
            User::where('id', $student->id)->lockForUpdate()->first();
            $available = ! DB::table('arsip_digital.dosen_signature_availability')->where('user_id', $data['lecturer_user_id'])->where('is_available', false)->exists();
            $lecturer = User::where('id', $data['lecturer_user_id'])->where('is_dosen', true)->first();
            if (! $available || ! $lecturer) {
                throw new HttpException(422, 'Dosen tidak tersedia.');
            }
            if (SignatureRequest::where('student_user_id', $student->id)->where('lecturer_user_id', $lecturer->id)->whereIn('status', ['requested', 'draft'])->exists()) {
                throw new HttpException(409, 'Masih ada request aktif untuk dosen ini.');
            }
            if (SignatureRequest::where('student_user_id', $student->id)->where('lecturer_user_id', $lecturer->id)->whereIn('status', ['rejected', 'cancelled', 'expired'])->where('finished_at', '>', now()->subHours($settings['signature_request_cooldown_hours']))->exists()) {
                throw new HttpException(429, 'Cooldown request masih berlaku.');
            }
            $studentName = MahasiswaView::where('nim', substr((string) $student->kd_user, 4))->value('nm_mhs') ?: $student->kd_user;
            $lecturerName = Dosen::where('kd_dosen', substr((string) $lecturer->kd_user, 4))->value('nm_dosen') ?: $lecturer->kd_user;
            $item = SignatureRequest::create(['student_user_id' => $student->id, 'lecturer_user_id' => $lecturer->id, 'student_name' => $studentName, 'lecturer_name' => $lecturerName, 'title' => $data['title'], 'description' => $data['description'] ?? null, 'status' => 'requested', 'expires_at' => now()->addDays($settings['signature_request_expiry_days'])]);
            $this->replaceFiles($item, $files);
            $this->notifications->sendToUser($lecturer->id, 'dosen', 'signature_request.requested', 'Request tanda tangan baru', $item->title, 'signature_request', $item->signature_request_id);
            $this->record('signature_request.requested', $item, $student->id, 'mahasiswa');

            return $item->load('files');
        }, 3);
    }

    public function updateRequested(int $id, object $student, array $data): SignatureRequest
    {
        $files = isset($data['file_ids']) ? $this->validateFiles($student, $data['file_ids']) : null;

        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($id, $student, $data, $files) {
            $item = SignatureRequest::where('student_user_id', $student->id)->lockForUpdate()->findOrFail($id);
            if ($item->status !== 'requested') {
                throw new HttpException(409, 'Hanya request berstatus requested yang dapat diubah.');
            }
            $item->update(array_intersect_key($data, array_flip(['title', 'description'])));
            if ($files !== null) {
                $item->files()->delete();
                $this->replaceFiles($item, $files);
            }
            $this->record('signature_request.updated', $item, $student->id, 'mahasiswa');

            return $item->refresh()->load('files');
        }, 3);
    }

    public function transition(int $id, object $lecturer, string $status, ?string $reason = null): SignatureRequest
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($id, $lecturer, $status, $reason) {
            $item = SignatureRequest::where('signature_request_id', $id)->where('lecturer_user_id', $lecturer->id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'requested') {
                throw new HttpException(409, 'Status request tidak dapat diubah.');
            }
            if ($status === 'rejected' && ! trim((string) $reason)) {
                throw new HttpException(422, 'Alasan penolakan wajib diisi.');
            }
            $item->update(['status' => $status, 'rejection_reason' => $reason, 'finished_at' => $status === 'rejected' ? now() : null]);
            $this->notifications->sendToUser($item->student_user_id, 'mahasiswa', "signature_request.{$status}", 'Status request tanda tangan berubah', $item->title, 'signature_request', $item->signature_request_id);
            $this->record("signature_request.{$status}", $item, $lecturer->id, 'dosen');

            return $item->refresh();
        }, 3);
    }

    public function bulk(array $ids, object $lecturer, string $status, ?string $reason): array
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($ids, $lecturer, $status, $reason) {
            $items = SignatureRequest::whereIn('signature_request_id', $ids)->where('lecturer_user_id', $lecturer->id)->where('status', 'requested')->orderBy('signature_request_id')->lockForUpdate()->get();
            if ($items->count() !== count($ids)) {
                throw new HttpException(422, 'Sebagian request bukan milik dosen atau status tidak valid.');
            }
            foreach ($items as $item) {
                $this->transition($item->signature_request_id, $lecturer, $status, $reason);
            }

            return $items->map(fn ($item) => $item->refresh())->all();
        }, 3);
    }

    public function createSession(int $fileId, object $lecturer): PdfSignSession
    {
        $file = SignatureRequestFile::with(['request', 'source', 'session'])->findOrFail($fileId);
        if ($file->request->lecturer_user_id !== $lecturer->id || $file->request->status !== 'draft') {
            throw new HttpException(403, 'Tidak memiliki akses memproses file.');
        }
        $this->assertSourceHash($file);
        $oldSession = $file->session;
        $limitMb = (int) $this->settings->getDefaults()['signature_request_max_file_size_mb'];
        $session = $this->signer->create($lecturer, 'dosen', $file->source, null, $limitMb);
        try {
            $session->update(['signature_request_file_id' => $file->signature_request_file_id]);
            $file->update(['sign_session_id' => $session->sign_session_id]);
        } catch (\Throwable $e) {
            $this->signer->delete($session);
            throw $e;
        }
        if ($oldSession) {
            $this->signer->delete($oldSession);
        }

        return $session;
    }

    public function syncFinalized(PdfSignSession $session, object $lecturer): SignatureRequestFile
    {
        $synced = DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($session, $lecturer) {
            $file = SignatureRequestFile::with('request')->where('signature_request_file_id', $session->signature_request_file_id)->lockForUpdate()->firstOrFail();
            if ($file->request->lecturer_user_id !== $lecturer->id || $file->request->status !== 'draft' || ! in_array($session->status, ['processing', 'finalized'], true) || ! $session->result_path) {
                throw new HttpException(409, 'Hasil tanda tangan belum final.');
            }
            if ($file->result_sha256 === $session->result_sha256 && $file->signed_result_path && Storage::disk($file->signed_result_disk)->exists($file->signed_result_path)) {
                return $file;
            }
            $path = "arsip-digital/tmp/signature-requests/{$file->signature_request_id}/{$file->signature_request_file_id}.pdf";
            try {
                $bytes = Storage::disk($session->storage_disk)->get($session->result_path);
            } catch (\Throwable) {
                throw new HttpException(500, 'Gagal menyimpan hasil tanda tangan request.');
            }
            if (! is_string($bytes) || $bytes === '' || ! str_starts_with($bytes, '%PDF') || ! Storage::disk('local')->put($path, $bytes)) {
                throw new HttpException(500, 'Gagal menyimpan hasil tanda tangan request.');
            }
            $hash = hash('sha256', $bytes);
            if ($hash !== $session->result_sha256) {
                Storage::disk('local')->delete($path);
                throw new HttpException(500, 'Gagal menyimpan hasil tanda tangan request.');
            }
            $file->update(['sign_session_id' => null, 'signed_result_disk' => 'local', 'signed_result_path' => $path, 'result_sha256' => $session->result_sha256, 'signed_at' => now()]);
            $this->record('signature_request.file_signed', $file->request, $lecturer->id, 'dosen', ['request_file_id' => $file->signature_request_file_id, 'result_sha256' => $file->result_sha256]);

            return $file->refresh();
        }, 3);

        return $synced;
    }

    public function cancel(int $id, object $student): SignatureRequest
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($id, $student) {
            $item = SignatureRequest::where('student_user_id', $student->id)->lockForUpdate()->findOrFail($id);
            if ($item->status !== 'requested') {
                throw new HttpException(409, 'Hanya request berstatus requested yang dapat dibatalkan.');
            }
            $item->update(['status' => 'cancelled', 'finished_at' => now()]);
            $this->notifications->sendToUser($item->lecturer_user_id, 'dosen', 'signature_request.cancelled', 'Request tanda tangan dibatalkan', $item->title, 'signature_request', $item->signature_request_id);
            $this->record('signature_request.cancelled', $item, $student->id, 'mahasiswa');

            return $item->refresh();
        }, 3);
    }

    public function send(int $id, object $lecturer): SignatureRequest
    {
        $uploaded = [];
        try {
            return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($id, $lecturer, &$uploaded) {
                $item = SignatureRequest::with('files')->where('lecturer_user_id', $lecturer->id)->lockForUpdate()->findOrFail($id);
                if ($item->status !== 'draft' || $item->files->isEmpty() || $item->files->contains(fn ($file) => ! $file->signed_result_path)) {
                    throw new HttpException(409, 'Semua file wajib ditandatangani sebelum dikirim.');
                }
                foreach ($item->files as $file) {
                    $this->assertSourceHash($file->load('source'));
                    if (! Storage::disk($file->signed_result_disk)->exists($file->signed_result_path) || hash('sha256', Storage::disk($file->signed_result_disk)->get($file->signed_result_path)) !== $file->result_sha256) {
                        throw new HttpException(409, 'Hasil tanda tangan tidak valid.');
                    }
                }
                $student = User::findOrFail($item->student_user_id);
                foreach ($item->files as $file) {
                    $path = Storage::disk($file->signed_result_disk)->path($file->signed_result_path);
                    $upload = new UploadedFile($path, 'signed-'.$file->source_filename, 'application/pdf', null, true);
                    $archive = $this->archives->uploadSignatureRequestResult($upload, $student, $lecturer, $item->signature_request_id, $item->lecturer_name, ['archive_type' => 'Permintaan berkas tanda tangan', 'display_type' => 'Permintaan berkas tanda tangan']);
                    $uploaded[] = $archive;
                    $file->update(['result_file_id' => $archive->file_id]);
                }
                $item->update(['status' => 'completed', 'finished_at' => now()]);
                $this->notifications->sendToUser($item->student_user_id, 'mahasiswa', 'signature_request.completed', 'Request tanda tangan selesai', $item->title, 'signature_request', $item->signature_request_id);
                $this->record('signature_request.completed', $item, $lecturer->id, 'dosen');

                return $item->refresh()->load('files');
            }, 3);
        } catch (\Throwable $e) {
            foreach ($uploaded as $file) {
                Storage::disk($file->storage_disk)->delete($file->storage_path);
            }
            throw $e;
        }
    }

    public function expire(): int
    {
        $items = SignatureRequest::where('status', 'requested')->where('expires_at', '<=', now())->get();
        foreach ($items as $item) {
            DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($item) {
                $locked = SignatureRequest::whereKey($item->signature_request_id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'requested') {
                    return;
                }
                $locked->update(['status' => 'expired', 'finished_at' => now()]);
                $this->notifications->sendToUser($locked->student_user_id, 'mahasiswa', 'signature_request.expired', 'Request tanda tangan kedaluwarsa', $locked->title, 'signature_request', $locked->signature_request_id);
                $this->notifications->sendToUser($locked->lecturer_user_id, 'dosen', 'signature_request.expired', 'Request tanda tangan kedaluwarsa', $locked->title, 'signature_request', $locked->signature_request_id);
                $this->record('signature_request.expired', $locked, null, 'system');
            });
        }

        return $items->count();
    }

    private function validateFiles(object $student, array $ids)
    {
        $settings = $this->settings->getDefaults();
        $ids = array_values(array_unique($ids));
        if (count($ids) > $settings['signature_request_max_files']) {
            throw new HttpException(422, 'Jumlah file melebihi batas pengaturan.');
        }
        $files = ArchiveFile::whereIn('file_id', $ids)->where('owner_user_id', $student->id)->where('owner_role', 'mahasiswa')->where('status', 'active')->where('is_current', true)->get();
        if ($files->contains(fn ($file) => $file->storage_availability === 'missing')) {
            throw new HttpException(410, 'PDF sumber tidak tersedia.');
        }
        $max = $settings['signature_request_max_file_size_mb'] * 1024 * 1024;
        if ($files->count() !== count($ids) || $files->contains(fn ($file) => $file->extension !== 'pdf') || $files->contains(fn ($file) => $file->file_size_bytes > $max) || $files->sum('file_size_bytes') > $settings['signature_request_max_total_size_mb'] * 1024 * 1024) {
            throw new HttpException(422, 'File wajib PDF aktif milik mahasiswa dan memenuhi batas ukuran.');
        }

        return $files;
    }

    private function replaceFiles(SignatureRequest $item, $files): void
    {
        foreach ($files as $file) {
            SignatureRequestFile::create(['signature_request_id' => $item->signature_request_id, 'source_file_id' => $file->file_id, 'source_sha256' => $file->checksum_sha256, 'source_filename' => $file->display_filename, 'source_size_bytes' => $file->file_size_bytes]);
        }
    }

    private function assertSourceHash(SignatureRequestFile $file): void
    {
        if (! $file->source || ! Storage::disk($file->source->storage_disk)->exists($file->source->storage_path) || hash('sha256', Storage::disk($file->source->storage_disk)->get($file->source->storage_path)) !== $file->source_sha256) {
            throw new HttpException(409, 'PDF sumber berubah atau tidak tersedia.');
        }
    }

    private function deleteSignedResult(SignatureRequestFile $file): void
    {
        if ($file->signed_result_path) {
            Storage::disk($file->signed_result_disk)->delete($file->signed_result_path);
        }
    }

    private function record(string $action, SignatureRequest $item, ?int $userId, string $role, array $metadata = []): void
    {
        $this->audit->record($action, 'signature_request', $item->signature_request_id, $item->title, $metadata, null, $userId, $role);
    }
}
