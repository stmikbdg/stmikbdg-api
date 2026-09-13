<?php

use App\Http\Controllers\ArsipDigital\AdminAuditLogController;
use App\Http\Controllers\ArsipDigital\AdminAuditLogNoteController;
use App\Http\Controllers\ArsipDigital\AdminDistributionBulkUploadController;
use App\Http\Controllers\ArsipDigital\AdminDistributionController;
use App\Http\Controllers\ArsipDigital\AdminExportJobController;
use App\Http\Controllers\ArsipDigital\AdminOfficialDocumentController;
use App\Http\Controllers\ArsipDigital\AdminRequestAssignmentController;
use App\Http\Controllers\ArsipDigital\AdminRequestController;
use App\Http\Controllers\ArsipDigital\AdminTargetController;
use App\Http\Controllers\ArsipDigital\ArchiveFileController;
use App\Http\Controllers\ArsipDigital\CategoryController;
use App\Http\Controllers\ArsipDigital\FoundationController;
use App\Http\Controllers\ArsipDigital\InstitutionalArchiveController;
use App\Http\Controllers\ArsipDigital\InstitutionalCategoryController;
use App\Http\Controllers\ArsipDigital\InstitutionalDistributionController;
use App\Http\Controllers\ArsipDigital\InstitutionalStorageMonitoringController;
use App\Http\Controllers\ArsipDigital\InstitutionalUnitController;
use App\Http\Controllers\ArsipDigital\NotificationController;
use App\Http\Controllers\ArsipDigital\ScholarshipController;
use App\Http\Controllers\ArsipDigital\SegmentController;
use App\Http\Controllers\ArsipDigital\UserDistributionController;
use App\Http\Controllers\ArsipDigital\UserRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('/arsip-digital')
    ->middleware('auth.jwt')
    ->group(function () {
        Route::get('/me/archive-summary', [FoundationController::class, 'archiveSummary']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/{notification_id}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        Route::get('/admin/academic-documents', [AdminOfficialDocumentController::class, 'index']);
        Route::get('/admin/academic-documents/signers', [AdminOfficialDocumentController::class, 'signers']);
        Route::get('/admin/academic-documents/next-number', [AdminOfficialDocumentController::class, 'nextNumber']);
        Route::post('/admin/academic-documents/preview', [AdminOfficialDocumentController::class, 'previewDraft']);
        Route::post('/admin/academic-documents', [AdminOfficialDocumentController::class, 'store']);
        Route::get('/admin/academic-documents/{official_document_id}', [AdminOfficialDocumentController::class, 'show'])
            ->whereNumber('official_document_id');
        Route::get('/admin/academic-documents/{official_document_id}/preview', [AdminOfficialDocumentController::class, 'preview'])
            ->whereNumber('official_document_id');
        Route::get('/admin/academic-documents/{official_document_id}/download', [AdminOfficialDocumentController::class, 'download'])
            ->whereNumber('official_document_id');
        Route::post('/admin/academic-documents/{official_document_id}/distribute', [AdminOfficialDocumentController::class, 'distribute'])
            ->whereNumber('official_document_id');
        Route::post('/admin/academic-documents/{official_document_id}/revoke', [AdminOfficialDocumentController::class, 'revoke'])
            ->whereNumber('official_document_id');
        Route::get('/admin/academic-documents/students/{mhs_id}/transcript', [FoundationController::class, 'transcript'])
            ->whereNumber('mhs_id');
        Route::get('/admin/settings', [FoundationController::class, 'settings']);

        Route::put('/admin/settings', [FoundationController::class, 'updateSettings']);

        Route::get('/admin/institutional-storage/summary', [InstitutionalStorageMonitoringController::class, 'summary']);
        Route::get('/admin/institutional-storage/files', [InstitutionalStorageMonitoringController::class, 'files']);
        Route::post('/admin/institutional-storage/reconciliation-jobs', [InstitutionalStorageMonitoringController::class, 'trigger']);
        Route::get('/admin/institutional-storage/reconciliation-jobs/{job_id}', [InstitutionalStorageMonitoringController::class, 'show'])->whereNumber('job_id');

        Route::get('/admin/institutional-units', [InstitutionalUnitController::class, 'index']);
        Route::post('/admin/institutional-units', [InstitutionalUnitController::class, 'store']);
        Route::put('/admin/institutional-units/{unit_id}', [InstitutionalUnitController::class, 'update'])->whereNumber('unit_id');
        Route::delete('/admin/institutional-units/{unit_id}', [InstitutionalUnitController::class, 'destroy'])->whereNumber('unit_id');
        Route::post('/admin/institutional-units/{unit_id}/restore', [InstitutionalUnitController::class, 'restore'])->whereNumber('unit_id');
        Route::get('/admin/institutional-categories', [InstitutionalCategoryController::class, 'index']);
        Route::post('/admin/institutional-categories', [InstitutionalCategoryController::class, 'store']);
        Route::put('/admin/institutional-categories/{category_id}', [InstitutionalCategoryController::class, 'update'])->whereNumber('category_id');
        Route::delete('/admin/institutional-categories/{category_id}', [InstitutionalCategoryController::class, 'destroy'])->whereNumber('category_id');
        Route::post('/admin/institutional-categories/{category_id}/restore', [InstitutionalCategoryController::class, 'restore'])->whereNumber('category_id');
        Route::get('/admin/institutional-archives/trash', [InstitutionalArchiveController::class, 'trash']);
        Route::get('/admin/institutional-archives', [InstitutionalArchiveController::class, 'index']);
        Route::post('/admin/institutional-archives', [InstitutionalArchiveController::class, 'store']);
        Route::get('/admin/institutional-archives/{id}', [InstitutionalArchiveController::class, 'show'])->whereNumber('id');
        Route::put('/admin/institutional-archives/{id}', [InstitutionalArchiveController::class, 'update'])->whereNumber('id');
        Route::post('/admin/institutional-archives/{id}/move', [InstitutionalArchiveController::class, 'move'])->whereNumber('id');
        Route::post('/admin/institutional-archives/{id}/versions', [InstitutionalArchiveController::class, 'uploadVersion'])->whereNumber('id');
        Route::get('/admin/institutional-archives/{id}/versions', [InstitutionalArchiveController::class, 'versions'])->whereNumber('id');
        Route::get('/admin/institutional-archives/{id}/versions/{fileId}/download', [InstitutionalArchiveController::class, 'downloadVersion'])->whereNumber('id')->whereNumber('fileId');
        Route::get('/admin/institutional-archives/{id}/preview', [InstitutionalArchiveController::class, 'preview'])->whereNumber('id');
        Route::get('/admin/institutional-archives/{id}/preview-verified', [InstitutionalArchiveController::class, 'previewVerified'])->whereNumber('id');
        Route::get('/admin/institutional-archives/{id}/download', [InstitutionalArchiveController::class, 'download'])->whereNumber('id');
        Route::get('/admin/institutional-archives/{id}/download-master', [InstitutionalArchiveController::class, 'downloadMaster'])->whereNumber('id');
        Route::post('/admin/institutional-archives/{id}/verification/retry', [InstitutionalArchiveController::class, 'retryVerification'])->whereNumber('id');
        Route::get('/admin/institutional-archives/{id}/timeline', [InstitutionalArchiveController::class, 'timeline'])->whereNumber('id');
        Route::delete('/admin/institutional-archives/{id}', [InstitutionalArchiveController::class, 'destroy'])->whereNumber('id');
        Route::post('/admin/institutional-archives/{id}/restore', [InstitutionalArchiveController::class, 'restore'])->whereNumber('id');
        Route::post('/admin/institutional-archives/{id}/distributions/preview-targets', [InstitutionalDistributionController::class, 'preview'])->whereNumber('id');
        Route::post('/admin/institutional-archives/{id}/distributions', [InstitutionalDistributionController::class, 'store'])->whereNumber('id');
        Route::get('/admin/institutional-archives/{id}/distributions', [InstitutionalDistributionController::class, 'index'])->whereNumber('id');
        Route::get('/admin/institutional-distributions/{distributionId}/targets', [InstitutionalDistributionController::class, 'draftTargets'])->whereNumber('distributionId');
        Route::get('/admin/institutional-distributions/{distributionId}/recipients', [InstitutionalDistributionController::class, 'recipients'])->whereNumber('distributionId');
        Route::post('/admin/institutional-distributions/{distributionId}/publish', [InstitutionalDistributionController::class, 'publish'])->whereNumber('distributionId');
        Route::post('/admin/institutional-distributions/{distributionId}/withdraw', [InstitutionalDistributionController::class, 'withdraw'])->whereNumber('distributionId');
        Route::get('/admin/institutional-distributions/{distributionId}', [InstitutionalDistributionController::class, 'show'])->whereNumber('distributionId');
        Route::put('/admin/institutional-distributions/{distributionId}', [InstitutionalDistributionController::class, 'update'])->whereNumber('distributionId');
        Route::delete('/admin/institutional-distributions/{distributionId}', [InstitutionalDistributionController::class, 'destroy'])->whereNumber('distributionId');

        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category_id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category_id}', [CategoryController::class, 'destroy']);
        Route::post('/categories/{category_id}/restore', [CategoryController::class, 'restore']);

        Route::get('/files', [ArchiveFileController::class, 'index']);
        Route::post('/files', [ArchiveFileController::class, 'store']);
        Route::post('/files/move', [ArchiveFileController::class, 'move']);
        Route::get('/files/{file_id}', [ArchiveFileController::class, 'show']);
        Route::get('/files/{file_id}/versions', [ArchiveFileController::class, 'versions']);
        Route::get('/files/{file_id}/download', [ArchiveFileController::class, 'download']);
        Route::delete('/files/{file_id}', [ArchiveFileController::class, 'destroy']);
        Route::post('/files/{file_id}/restore', [ArchiveFileController::class, 'restore']);

        Route::get('/admin/requests', [AdminRequestController::class, 'index']);
        Route::get('/admin/targets', [AdminTargetController::class, 'index']);
        Route::post('/admin/requests', [AdminRequestController::class, 'store']);
        Route::post('/admin/requests/preview-targets', [AdminRequestController::class, 'previewTargets']);
        Route::get('/admin/requests/{request_id}', [AdminRequestController::class, 'show']);
        Route::put('/admin/requests/{request_id}', [AdminRequestController::class, 'update']);
        Route::delete('/admin/requests/{request_id}', [AdminRequestController::class, 'destroy']);
        Route::post('/admin/requests/{request_id}/publish', [AdminRequestController::class, 'publish']);
        Route::post('/admin/requests/{request_id}/targets', [AdminRequestController::class, 'appendTargets']);
        Route::post('/admin/requests/{request_id}/close', [AdminRequestController::class, 'close']);
        Route::post('/admin/requests/{request_id}/reopen', [AdminRequestController::class, 'reopen']);
        Route::post('/admin/requests/{request_id}/archive', [AdminRequestController::class, 'archive']);
        Route::get('/admin/requests/{request_id}/assignments', [AdminRequestAssignmentController::class, 'assignments']);
        Route::get('/admin/requests/{request_id}/progress', [AdminRequestAssignmentController::class, 'progress']);
        Route::post('/admin/request-assignments/bulk-approve', [AdminRequestAssignmentController::class, 'bulkApprove']);
        Route::post('/admin/request-assignments/bulk-reject', [AdminRequestAssignmentController::class, 'bulkReject']);
        Route::post('/admin/request-assignments/{assignment_id}/approve', [AdminRequestAssignmentController::class, 'approve']);
        Route::post('/admin/request-assignments/{assignment_id}/reject', [AdminRequestAssignmentController::class, 'reject']);
        Route::get('/admin/request-files/{request_file_id}/download', [AdminRequestAssignmentController::class, 'downloadRequestFile']);
        Route::post('/admin/files/upload-for-user', [AdminRequestAssignmentController::class, 'uploadForUser']);

        Route::get('/requests', [UserRequestController::class, 'index']);
        Route::get('/requests/{request_id}', [UserRequestController::class, 'show']);
        Route::post('/request-assignments/{assignment_id}/files/upload', [UserRequestController::class, 'upload']);
        Route::post('/request-assignments/{assignment_id}/files/reuse', [UserRequestController::class, 'reuse']);

        Route::get('/admin/distributions', [AdminDistributionController::class, 'index']);
        Route::post('/admin/distributions', [AdminDistributionController::class, 'store']);
        Route::post('/admin/distributions/preview-targets', [AdminDistributionController::class, 'previewTargets']);
        Route::get('/admin/distributions/{distribution_id}', [AdminDistributionController::class, 'show']);
        Route::put('/admin/distributions/{distribution_id}', [AdminDistributionController::class, 'update']);
        Route::delete('/admin/distributions/{distribution_id}', [AdminDistributionController::class, 'destroy']);
        Route::post('/admin/distributions/{distribution_id}/publish', [AdminDistributionController::class, 'publish']);
        Route::post('/admin/distributions/{distribution_id}/withdraw', [AdminDistributionController::class, 'withdraw']);
        Route::post('/admin/distributions/{distribution_id}/corrections', [AdminDistributionController::class, 'createCorrection']);
        Route::get('/admin/distributions/{distribution_id}/recipients', [AdminDistributionController::class, 'recipients']);
        Route::get('/admin/distributions/{distribution_id}/bulk-upload-jobs', [AdminDistributionBulkUploadController::class, 'index']);
        Route::post('/admin/distributions/{distribution_id}/bulk-upload-jobs', [AdminDistributionBulkUploadController::class, 'store']);
        Route::post('/admin/distribution-recipients/{recipient_id}/file', [AdminDistributionController::class, 'uploadRecipientFile']);
        Route::get('/admin/distribution-bulk-upload-jobs/{bulk_upload_job_id}', [AdminDistributionBulkUploadController::class, 'show']);
        Route::post('/admin/distribution-bulk-upload-jobs/{bulk_upload_job_id}/confirm', [AdminDistributionBulkUploadController::class, 'confirm']);
        Route::post('/admin/distribution-bulk-upload-jobs/{bulk_upload_job_id}/cancel', [AdminDistributionBulkUploadController::class, 'cancel']);

        Route::get('/distributions', [UserDistributionController::class, 'index']);
        Route::get('/distribution-files/{file_id}/preview', [UserDistributionController::class, 'preview']);
        Route::get('/distribution-files/{file_id}/download', [UserDistributionController::class, 'download']);
        Route::get('/distribution-recipients/{recipient_id}/preview', [UserDistributionController::class, 'previewRecipient']);
        Route::get('/distribution-recipients/{recipient_id}/download', [UserDistributionController::class, 'downloadRecipient']);

        Route::get('/admin/export-jobs', [AdminExportJobController::class, 'index']);
        Route::post('/admin/export-jobs', [AdminExportJobController::class, 'store']);
        Route::get('/admin/export-jobs/{export_job_id}', [AdminExportJobController::class, 'show']);
        Route::get('/admin/export-jobs/{export_job_id}/download', [AdminExportJobController::class, 'download']);

        Route::get('/admin/audit-logs', [AdminAuditLogController::class, 'index']);
        Route::get('/admin/audit-logs/{auditLogId}/note', [AdminAuditLogNoteController::class, 'show'])->whereNumber('auditLogId');
        Route::post('/admin/audit-logs/{auditLogId}/note', [AdminAuditLogNoteController::class, 'store'])->whereNumber('auditLogId');
        Route::put('/admin/audit-logs/{auditLogId}/note', [AdminAuditLogNoteController::class, 'update'])->whereNumber('auditLogId');

        Route::get('/admin/scholarship-types', [ScholarshipController::class, 'types']);
        Route::post('/admin/scholarship-types', [ScholarshipController::class, 'storeType']);
        Route::put('/admin/scholarship-types/{scholarship_type_id}', [ScholarshipController::class, 'updateType']);
        Route::delete('/admin/scholarship-types/{scholarship_type_id}', [ScholarshipController::class, 'deleteType']);
        Route::get('/admin/student-scholarships', [ScholarshipController::class, 'studentScholarships']);
        Route::post('/admin/student-scholarships', [ScholarshipController::class, 'storeStudentScholarship']);
        Route::post('/admin/student-scholarships/import', [ScholarshipController::class, 'importStudentScholarships']);
        Route::put('/admin/student-scholarships/{student_scholarship_id}', [ScholarshipController::class, 'updateStudentScholarship']);

        Route::get('/admin/segments', [SegmentController::class, 'index']);
        Route::post('/admin/segments', [SegmentController::class, 'store']);
        Route::get('/admin/segments/{segment_id}', [SegmentController::class, 'show']);
        Route::put('/admin/segments/{segment_id}', [SegmentController::class, 'update']);
        Route::delete('/admin/segments/{segment_id}', [SegmentController::class, 'destroy']);
        Route::post('/admin/segments/{segment_id}/members', [SegmentController::class, 'addMember']);
        Route::delete('/admin/segments/{segment_id}/members/{segment_member_id}', [SegmentController::class, 'deleteMember']);
        Route::post('/admin/segments/{segment_id}/import', [SegmentController::class, 'importMembers']);
    });
