<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\Distribution;
use App\Models\ArsipDigital\DistributionRecipient;
use App\Services\ArsipDigital\AuditLogService;
use App\Services\ArsipDigital\DistributionService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class AdminDistributionController extends Controller
{
    public function index(Request $request, RoleResolverService $roleResolver, DistributionService $distributionService)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $filters = $request->validate([
                'target_role' => ['sometimes', 'in:mahasiswa,dosen'],
                'scope_type' => ['sometimes', 'in:filter,specific,segment'],
                'status' => ['sometimes', 'in:draft,published,closed,archived'],
                'with_deleted' => ['sometimes', 'boolean'],
                'search' => ['sometimes', 'string', 'max:255'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);
            $distributions = $distributionService->adminQuery($filters)
                ->withCount('recipients')
                ->paginate($filters['per_page'] ?? 50);

            return $this->successfulResponseJSON([
                'distributions' => $distributions->items(),
                'meta' => [
                    'current_page' => $distributions->currentPage(),
                    'last_page' => $distributions->lastPage(),
                    'per_page' => $distributions->perPage(),
                    'total' => $distributions->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(Request $request, RoleResolverService $roleResolver, DistributionService $distributionService)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $payload = $this->validatedPayload($request);
            $distribution = $distributionService->create($payload, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['distribution' => $distribution->toArray()], 'Distribution berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function show(Request $request, int $distribution_id, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $distribution = Distribution::with(['recipients.file'])->findOrFail($distribution_id);

            return $this->successfulResponseJSON(['distribution' => $distribution->toArray()]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function update(Request $request, int $distribution_id, RoleResolverService $roleResolver, DistributionService $distributionService, AuditLogService $auditLog)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $distribution = Distribution::findOrFail($distribution_id);
            $payload = $this->validatedPayload($request, true);
            $distribution = $distributionService->update($distribution, $payload);

            $auditLog->record('distribution.updated', 'distribution', $distribution->distribution_id, 'Distribution arsip digital diperbarui.', ['updated_keys' => array_keys($payload)], $request, auth()->user()?->id, $role);

            return $this->successfulResponseJSON(['distribution' => $distribution->toArray()], 'Distribution berhasil diperbarui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function destroy(Request $request, int $distribution_id, RoleResolverService $roleResolver, DistributionService $distributionService, AuditLogService $auditLog)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $distribution = Distribution::findOrFail($distribution_id);
            $distributionService->delete($distribution);

            $auditLog->record('distribution.deleted', 'distribution', $distribution_id, 'Distribution arsip digital dihapus secara soft delete.', [], $request, auth()->user()?->id, $role);

            return $this->successfulResponseJSONV2('Distribution berhasil dihapus.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function previewTargets(Request $request, RoleResolverService $roleResolver, DistributionService $distributionService)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'target_role' => ['required', 'in:mahasiswa,dosen'],
                'scope_type' => ['required', 'in:filter,specific,segment'],
                'target_filters' => ['nullable', 'array'],
                'target_identifiers' => ['nullable'],
                'target_segment_ids' => ['nullable', 'array'],
                'target_segment_ids.*' => ['integer'],
            ]);

            return $this->successfulResponseJSON(['preview' => $distributionService->previewForPayload($payload)]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function publish(Request $request, int $distribution_id, RoleResolverService $roleResolver, DistributionService $distributionService)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $distribution = Distribution::findOrFail($distribution_id);
            $distribution = $distributionService->publish($distribution, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['distribution' => $distribution->toArray()], 'Distribution berhasil dipublish.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function withdraw(Request $request, int $distribution_id, RoleResolverService $roleResolver, DistributionService $distributionService)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
            $distribution = $distributionService->withdraw(Distribution::findOrFail($distribution_id), $reason, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['distribution' => $distribution->toArray()], 'Distribution berhasil ditarik.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function createCorrection(Request $request, int $distribution_id, RoleResolverService $roleResolver, DistributionService $distributionService)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $distribution = $distributionService->createCorrection(Distribution::findOrFail($distribution_id), auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['distribution' => $distribution->toArray()], 'Correction berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function recipients(Request $request, int $distribution_id, RoleResolverService $roleResolver, DistributionService $distributionService)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            Distribution::findOrFail($distribution_id);
            $filters = $request->validate([
                'delivery_status' => ['sometimes', 'in:pending,file_uploaded,available,downloaded'],
                'target_role' => ['sometimes', 'in:mahasiswa,dosen'],
                'identifier' => ['sometimes', 'string', 'max:100'],
                'angkatan' => ['sometimes', 'string', 'max:20'],
                'search' => ['sometimes', 'string', 'max:255'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            $recipients = $distributionService->recipientQuery($distribution_id, $filters)
                ->paginate($filters['per_page'] ?? 50);

            return $this->successfulResponseJSON([
                'recipients' => $recipients->items(),
                'meta' => [
                    'current_page' => $recipients->currentPage(),
                    'last_page' => $recipients->lastPage(),
                    'per_page' => $recipients->perPage(),
                    'total' => $recipients->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function uploadRecipientFile(Request $request, int $recipient_id, RoleResolverService $roleResolver, DistributionService $distributionService)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'file' => ['required', 'file'],
                'display_filename' => ['nullable', 'string', 'max:255'],
                'note' => ['nullable', 'string'],
            ]);
            $recipient = DistributionRecipient::with(['distribution', 'file'])->findOrFail($recipient_id);
            $recipient = $distributionService->uploadRecipientFile($recipient, $request->file('file'), $payload, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['recipient' => $recipient->toArray()], 'File distribution berhasil diupload.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function validatedPayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'target_role' => [$required, 'in:mahasiswa,dosen'],
            'scope_type' => [$required, 'in:filter,specific,segment'],
            'target_filters' => ['nullable', 'array'],
            'target_identifiers' => ['nullable'],
            'target_segment_ids' => ['nullable', 'array'],
            'target_segment_ids.*' => ['integer'],
        ]);
    }
}
