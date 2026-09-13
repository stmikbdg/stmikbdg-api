<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\OfficialDocument;
use App\Services\ArsipDigital\AcademicDocumentDataService;
use App\Services\ArsipDigital\ArsipDigitalStorageService;
use App\Services\ArsipDigital\OfficialDocumentDistributionService;
use App\Services\ArsipDigital\OfficialDocumentIssuanceService;
use App\Services\ArsipDigital\OfficialDocumentNumberService;
use App\Services\ArsipDigital\OfficialDocumentPdfService;
use App\Services\ArsipDigital\OfficialDocumentSignerService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AdminOfficialDocumentController extends Controller
{
    public function index(Request $request, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $filters = $request->validate([
                'document_type' => ['sometimes', 'in:khs,transcript'],
                'status' => ['sometimes', 'in:issued,revoked,replaced'],
                'search' => ['sometimes', 'string', 'max:255'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            $documents = OfficialDocument::query()
                ->with(['file', 'distribution'])
                ->when($filters['document_type'] ?? null, fn (Builder $query, string $type) => $query->where('document_type', $type))
                ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
                ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                    $query->where(function (Builder $query) use ($search): void {
                        $query->where('document_number', 'ilike', '%'.$search.'%')
                            ->orWhere('subject_identifier', 'ilike', '%'.$search.'%')
                            ->orWhere('subject_name_snapshot', 'ilike', '%'.$search.'%');
                    });
                })
                ->orderByDesc('issued_at')
                ->orderByDesc('official_document_id')
                ->paginate($filters['per_page'] ?? 25);

            return $this->successfulResponseJSON([
                'documents' => $documents->items(),
                'meta' => [
                    'current_page' => $documents->currentPage(),
                    'last_page' => $documents->lastPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function signers(
        Request $request,
        RoleResolverService $roleResolver,
        OfficialDocumentSignerService $signerService
    ) {
        try {
            $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
            ]);

            return $this->successfulResponseJSON([
                'signers' => $signerService->directory($payload['search'] ?? null),
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function nextNumber(
        Request $request,
        RoleResolverService $roleResolver,
        OfficialDocumentNumberService $numberService
    ) {
        try {
            $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'document_type' => ['required', 'in:transcript'],
            ]);

            return $this->successfulResponseJSON([
                'document_number' => $numberService->suggest($payload['document_type']),
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function previewDraft(
        Request $request,
        RoleResolverService $roleResolver,
        AcademicDocumentDataService $academicData,
        OfficialDocumentPdfService $pdfService,
        OfficialDocumentSignerService $signerService
    ) {
        try {
            $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'document_type' => ['required', 'in:transcript'],
                'document_number' => ['required', 'string', 'max:100'],
                'mhs_id' => ['required', 'integer', 'min:1'],
                'signer_user_id' => ['required', 'integer', 'min:1'],
                'signer_title' => ['required', 'string', 'max:150'],
            ]);
            $semester = $payload['document_type'] === 'khs' ? (int) $payload['semester'] : null;
            $snapshot = $academicData->documentForStudent(
                (int) $payload['mhs_id'],
                $payload['document_type'],
                $semester
            );
            $signer = $signerService->snapshot((int) $payload['signer_user_id'], $payload['signer_title']);
            $bytes = $pdfService->render(
                $payload['document_type'],
                trim($payload['document_number']),
                $snapshot,
                $semester,
                null,
                true,
                $signer
            );

            return response($bytes, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="preview-'.$pdfService->filename($payload['document_type'], (string) $snapshot['student']['nim'], $semester).'"',
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(
        Request $request,
        RoleResolverService $roleResolver,
        OfficialDocumentIssuanceService $issuanceService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'document_type' => ['required', 'in:transcript'],
                'document_number' => ['required', 'string', 'max:100'],
                'mhs_id' => ['required', 'integer', 'min:1'],
                'signer_user_id' => ['required', 'integer', 'min:1'],
                'signer_title' => ['required', 'string', 'max:150'],
                'replaces_document_id' => ['nullable', 'integer', 'min:1'],
                'replacement_reason' => ['required_with:replaces_document_id', 'nullable', 'string', 'min:5', 'max:1000'],
            ]);

            $document = $issuanceService->issue($payload, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(
                ['document' => $document->toArray()],
                'Dokumen akademik resmi berhasil diterbitkan.',
                201
            );
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function distribute(
        Request $request,
        int $official_document_id,
        RoleResolverService $roleResolver,
        OfficialDocumentDistributionService $distributionService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $document = OfficialDocument::findOrFail($official_document_id);
            $distribution = $distributionService->distribute($document, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(
                ['distribution' => $distribution->toArray()],
                'Dokumen akademik resmi berhasil didistribusikan.',
                201
            );
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function revoke(
        Request $request,
        int $official_document_id,
        RoleResolverService $roleResolver,
        OfficialDocumentIssuanceService $issuanceService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'reason' => ['required', 'string', 'min:5', 'max:1000'],
            ]);
            $document = OfficialDocument::findOrFail($official_document_id);
            $document = $issuanceService->revoke($document, $payload['reason'], auth()->user(), $role, $request);

            return $this->successfulResponseJSON(
                ['document' => $document->toArray()],
                'Dokumen akademik resmi berhasil dicabut.'
            );
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function show(Request $request, int $official_document_id, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $document = OfficialDocument::with(['file', 'distribution'])->findOrFail($official_document_id);

            return $this->successfulResponseJSON(['document' => $document->toArray()]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function preview(
        Request $request,
        int $official_document_id,
        RoleResolverService $roleResolver,
        ArsipDigitalStorageService $storageService
    ) {
        return $this->streamDocument($request, $official_document_id, $roleResolver, $storageService, false);
    }

    public function download(
        Request $request,
        int $official_document_id,
        RoleResolverService $roleResolver,
        ArsipDigitalStorageService $storageService
    ) {
        return $this->streamDocument($request, $official_document_id, $roleResolver, $storageService, true);
    }

    private function streamDocument(
        Request $request,
        int $officialDocumentId,
        RoleResolverService $roleResolver,
        ArsipDigitalStorageService $storageService,
        bool $download
    ) {
        try {
            $roleResolver->resolve($request, ['admin']);
            $document = OfficialDocument::with('file')->findOrFail($officialDocumentId);

            if (! $document->file) {
                abort(404, 'File dokumen resmi tidak ditemukan.');
            }

            return $storageService->streamPdfPrivate(
                $document->file->storage_disk,
                $document->file->storage_path,
                $document->file->display_filename,
                $download
            );
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
