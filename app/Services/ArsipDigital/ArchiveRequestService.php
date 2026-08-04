<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\ArchiveRequest;
use App\Models\ArsipDigital\RequestAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ArchiveRequestService
{
    public function __construct(
        private readonly RequestTargetPreviewService $targetPreview,
        private readonly AuditLogService $auditLog,
        private readonly NotificationService $notifications,
    ) {}

    public function adminQuery(array $filters = []): Builder
    {
        $query = ArchiveRequest::query();

        if (! empty($filters['with_deleted'])) {
            $query->withTrashed();
        }

        foreach (['target_role', 'scope_type', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['search'])) {
            $search = '%'.strtolower($filters['search']).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->whereRaw('LOWER(title) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$search]);
            });
        }

        return $query->orderByDesc('created_at')->orderByDesc('request_id');
    }

    public function userQuery(object $user, string $role): Builder
    {
        return ArchiveRequest::where('status', 'published')
            ->whereHas('assignments', function (Builder $query) use ($user, $role): void {
                $query->where('target_user_id', $user->id)
                    ->where('target_role', $role);
            })
            ->with(['assignments' => function ($query) use ($user, $role): void {
                $query->where('target_user_id', $user->id)
                    ->where('target_role', $role)
                    ->with('requestFiles.file');
            }])
            ->orderByDesc('published_at')
            ->orderByDesc('request_id');
    }

    public function create(array $payload, object $user): ArchiveRequest
    {
        return ArchiveRequest::create([
            'title' => $payload['title'],
            'description' => $payload['description'] ?? null,
            'target_role' => $payload['target_role'],
            'scope_type' => $payload['scope_type'],
            'target_filters' => $payload['target_filters'] ?? null,
            'target_identifiers' => $payload['target_identifiers'] ?? null,
            'target_segment_ids' => $payload['target_segment_ids'] ?? null,
            'max_files' => $payload['max_files'] ?? 1,
            'max_file_size_mb' => $payload['max_file_size_mb'] ?? null,
            'allowed_extensions' => isset($payload['allowed_extensions'])
                ? array_values(array_unique(array_map('strtolower', $payload['allowed_extensions'])))
                : null,
            'requires_verification' => $payload['requires_verification'] ?? true,
            'allow_file_reuse' => $payload['allow_file_reuse'] ?? true,
            'allow_inactive_upload' => $payload['allow_inactive_upload'] ?? false,
            'deadline_at' => $payload['deadline_at'] ?? null,
            'close_after_deadline' => $payload['close_after_deadline'] ?? false,
            'status' => 'draft',
            'created_by_user_id' => $user->id,
        ]);
    }

    public function update(ArchiveRequest $request, array $payload): ArchiveRequest
    {
        if ($request->status !== 'draft') {
            throw new HttpException(422, 'Request hanya dapat diubah saat status draft.');
        }

        $request->fill([
            'title' => $payload['title'] ?? $request->title,
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $request->description,
            'target_role' => $payload['target_role'] ?? $request->target_role,
            'scope_type' => $payload['scope_type'] ?? $request->scope_type,
            'target_filters' => array_key_exists('target_filters', $payload) ? $payload['target_filters'] : $request->target_filters,
            'target_identifiers' => array_key_exists('target_identifiers', $payload) ? $payload['target_identifiers'] : $request->target_identifiers,
            'target_segment_ids' => array_key_exists('target_segment_ids', $payload) ? $payload['target_segment_ids'] : $request->target_segment_ids,
            'max_files' => $payload['max_files'] ?? $request->max_files,
            'max_file_size_mb' => array_key_exists('max_file_size_mb', $payload) ? $payload['max_file_size_mb'] : $request->max_file_size_mb,
            'allowed_extensions' => isset($payload['allowed_extensions'])
                ? array_values(array_unique(array_map('strtolower', $payload['allowed_extensions'])))
                : $request->allowed_extensions,
            'requires_verification' => $payload['requires_verification'] ?? $request->requires_verification,
            'allow_file_reuse' => $payload['allow_file_reuse'] ?? $request->allow_file_reuse,
            'allow_inactive_upload' => $payload['allow_inactive_upload'] ?? $request->allow_inactive_upload,
            'deadline_at' => array_key_exists('deadline_at', $payload) ? $payload['deadline_at'] : $request->deadline_at,
            'close_after_deadline' => $payload['close_after_deadline'] ?? $request->close_after_deadline,
        ]);
        $request->save();

        return $request;
    }

    public function delete(ArchiveRequest $request): void
    {
        if ($request->status !== 'draft') {
            throw new HttpException(422, 'Request hanya dapat dihapus saat status draft.');
        }

        $request->delete();
    }

    public function close(ArchiveRequest $request, object $actor, string $actorRole, $httpRequest = null): ArchiveRequest
    {
        if ($request->status !== 'published') {
            throw new HttpException(422, 'Hanya request published yang dapat ditutup.');
        }

        $request->fill([
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        $request->save();

        $this->auditLog->record(
            'request.closed',
            'request',
            $request->request_id,
            'Request arsip digital ditutup manual.',
            [],
            $httpRequest,
            $actor->id,
            $actorRole
        );

        return $request->fresh(['assignments']);
    }

    public function reopen(ArchiveRequest $request, object $actor, string $actorRole, $httpRequest = null): ArchiveRequest
    {
        if ($request->status !== 'closed') {
            throw new HttpException(422, 'Hanya request closed yang dapat dibuka lagi.');
        }

        $request->fill([
            'status' => 'published',
            'closed_at' => null,
        ]);
        $request->save();

        $this->auditLog->record(
            'request.reopened',
            'request',
            $request->request_id,
            'Request arsip digital dibuka lagi.',
            [],
            $httpRequest,
            $actor->id,
            $actorRole
        );

        return $request->fresh(['assignments']);
    }

    public function archive(ArchiveRequest $request, object $actor, string $actorRole, $httpRequest = null): ArchiveRequest
    {
        if (! in_array($request->status, ['draft', 'closed'], true)) {
            throw new HttpException(422, 'Request hanya dapat diarsipkan saat draft atau closed.');
        }

        $previousStatus = $request->status;
        $request->fill(['status' => 'archived']);
        if ($request->closed_at === null) {
            $request->closed_at = now();
        }
        $request->save();

        $this->auditLog->record(
            'request.archived',
            'request',
            $request->request_id,
            'Request arsip digital diarsipkan.',
            ['previous_status' => $previousStatus],
            $httpRequest,
            $actor->id,
            $actorRole
        );

        return $request->fresh(['assignments']);
    }

    public function previewForPayload(array $payload): array
    {
        return $this->targetPreview->preview($payload);
    }

    public function previewForRequest(ArchiveRequest $request): array
    {
        return $this->targetPreview->preview([
            'target_role' => $request->target_role,
            'scope_type' => $request->scope_type,
            'target_filters' => $request->target_filters ?? [],
            'target_identifiers' => $request->target_identifiers ?? [],
            'target_segment_ids' => $request->target_segment_ids ?? [],
        ]);
    }

    public function publish(ArchiveRequest $request, object $actor, string $actorRole, $httpRequest = null): ArchiveRequest
    {
        if ($request->status !== 'draft') {
            throw new HttpException(422, 'Hanya request draft yang dapat dipublish.');
        }

        $preview = $this->previewForRequest($request);

        if ($preview['total_invalid'] > 0) {
            throw new HttpException(422, 'Request tidak dapat dipublish karena masih memiliki target invalid.');
        }

        if ($preview['total_valid'] < 1) {
            throw new HttpException(422, 'Request tidak dapat dipublish tanpa target valid.');
        }

        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($request, $preview, $actor, $actorRole, $httpRequest): ArchiveRequest {
            $request->fill([
                'status' => 'published',
                'published_at' => now(),
            ]);
            $request->save();

            $assignments = [];
            foreach ($preview['valid_targets'] as $target) {
                $assignments[] = $this->createAssignmentFromTarget($request, $target, $actor, $actorRole, $httpRequest, 'request_assignment.created', 'Assignment request arsip digital dibuat saat publish.');
            }

            $this->notifyAssignments($assignments, $request, 'request_published');

            $this->auditLog->record(
                'request.published',
                'request',
                $request->request_id,
                'Request arsip digital dipublish.',
                ['total_assignments' => $preview['total_valid']],
                $httpRequest,
                $actor->id,
                $actorRole
            );

            return $request->fresh(['assignments']);
        });
    }

    public function appendTargets(ArchiveRequest $request, array $payload, object $actor, string $actorRole, $httpRequest = null): array
    {
        if ($request->status !== 'published') {
            throw new HttpException(422, 'Target hanya dapat ditambahkan ke request published.');
        }

        if ($payload['target_role'] !== $request->target_role) {
            throw new HttpException(422, 'Role target tambahan harus sama dengan role request.');
        }

        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($request, $payload, $actor, $actorRole, $httpRequest): array {
            $preview = $this->previewForPayload($payload);
            $existingIdentifiers = RequestAssignment::where('request_id', $request->request_id)
                ->where('target_role', $request->target_role)
                ->lockForUpdate()
                ->pluck('identifier')
                ->map(fn ($identifier): string => trim((string) $identifier))
                ->flip();

            $created = [];
            $createdAssignments = [];
            $duplicates = [];

            foreach ($preview['valid_targets'] as $target) {
                $identifier = trim((string) $target['identifier']);

                if ($existingIdentifiers->has($identifier)) {
                    $duplicates[] = $target;

                    continue;
                }

                $assignment = RequestAssignment::query()->createOrFirst(
                    [
                        'request_id' => $request->request_id,
                        'target_role' => $target['target_role'],
                        'identifier' => $target['identifier'],
                    ],
                    $this->assignmentAttributesFromTarget($target)
                );

                if ($assignment->wasRecentlyCreated) {
                    $created[] = $target + ['assignment_id' => $assignment->assignment_id];
                    $createdAssignments[] = $assignment;
                    $existingIdentifiers->put($identifier, true);
                    $this->auditAssignmentCreated($assignment, $request, $actor, $actorRole, $httpRequest, 'request_assignment.appended', 'Assignment request arsip digital ditambahkan setelah publish.');

                    continue;
                }

                $duplicates[] = $target;
                $existingIdentifiers->put($identifier, true);
            }

            $this->notifyAssignments($createdAssignments, $request, 'request_target_added');

            $this->auditLog->record(
                'request.targets_appended',
                'request',
                $request->request_id,
                'Target request arsip digital ditambahkan setelah publish.',
                [
                    'created' => count($created),
                    'skipped_duplicate' => count($duplicates),
                    'invalid' => $preview['total_invalid'],
                ],
                $httpRequest,
                $actor->id,
                $actorRole
            );

            return [
                'created' => count($created),
                'skipped_duplicate' => count($duplicates),
                'invalid' => $preview['total_invalid'],
                'created_targets' => array_values($created),
                'duplicate_targets' => array_values($duplicates),
                'invalid_targets' => $preview['invalid_targets'],
            ];
        });
    }

    private function createAssignmentFromTarget(ArchiveRequest $request, array $target, object $actor, string $actorRole, $httpRequest, string $event, string $message): RequestAssignment
    {
        $assignment = RequestAssignment::create([
            'request_id' => $request->request_id,
            'target_role' => $target['target_role'],
            'identifier' => $target['identifier'],
        ] + $this->assignmentAttributesFromTarget($target));

        $this->auditAssignmentCreated($assignment, $request, $actor, $actorRole, $httpRequest, $event, $message);

        return $assignment;
    }

    private function assignmentAttributesFromTarget(array $target): array
    {
        return [
            'target_user_id' => $target['target_user_id'],
            'name_snapshot' => $target['name_snapshot'],
            'angkatan_snapshot' => $target['angkatan_snapshot'],
            'prodi_snapshot' => $target['prodi_snapshot'],
            'status_snapshot' => $target['status_snapshot'],
            'scholarship_snapshot' => $target['scholarship_snapshot'],
            'metadata' => $target['metadata'],
            'status' => 'not_submitted',
        ];
    }

    private function auditAssignmentCreated(RequestAssignment $assignment, ArchiveRequest $request, object $actor, string $actorRole, $httpRequest, string $event, string $message): void
    {
        $this->auditLog->record(
            $event,
            'request_assignment',
            $assignment->assignment_id,
            $message,
            ['request_id' => $request->request_id, 'identifier' => $assignment->identifier],
            $httpRequest,
            $actor->id,
            $actorRole
        );
    }

    private function notifyAssignments(array $assignments, ArchiveRequest $request, string $type): void
    {
        $this->notifications->sendToManyUsers($assignments, [
            'type' => $type,
            'title' => $request->title,
            'message' => $request->description,
            'entity_type' => 'request',
            'entity_id' => $request->request_id,
        ]);
    }

    public function fileSummary(ArchiveRequest $request): array
    {
        $assignments = RequestAssignment::where('request_id', $request->request_id)->with('requestFiles')->get();
        $currentFiles = $assignments
            ->flatMap(fn (RequestAssignment $assignment) => $assignment->requestFiles)
            ->filter(fn ($requestFile): bool => (bool) $requestFile->is_current && $requestFile->deleted_at === null);

        return [
            'total_current_files' => $currentFiles->count(),
            'assignments_with_files' => $currentFiles->pluck('assignment_id')->unique()->count(),
            'waiting_verification_files' => $currentFiles->where('status', 'waiting_verification')->count(),
            'approved_files' => $currentFiles->where('status', 'approved')->count(),
            'rejected_files' => $currentFiles->where('status', 'rejected')->count(),
            'late_files' => $currentFiles->where('is_late', true)->count(),
        ];
    }

    public function progress(ArchiveRequest $request): array
    {
        $counts = RequestAssignment::where('request_id', $request->request_id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $submitted = RequestAssignment::where('request_id', $request->request_id)
            ->whereIn('status', ['waiting_verification', 'approved', 'rejected'])
            ->count();

        $total = RequestAssignment::where('request_id', $request->request_id)->count();

        return [
            'request_id' => $request->request_id,
            'total_assignments' => $total,
            'submitted' => $submitted,
            'pending' => (int) ($counts['not_submitted'] ?? 0),
            'waiting_verification' => (int) ($counts['waiting_verification'] ?? 0),
            'approved' => (int) ($counts['approved'] ?? 0),
            'rejected' => (int) ($counts['rejected'] ?? 0),
            'closed' => (int) ($counts['closed'] ?? 0),
        ];
    }
}
