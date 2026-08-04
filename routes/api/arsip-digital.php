<?php

use App\Http\Controllers\ArsipDigital\AdminAuditLogController;
use App\Http\Controllers\ArsipDigital\AdminDistributionBulkUploadController;
use App\Http\Controllers\ArsipDigital\AdminDistributionController;
use App\Http\Controllers\ArsipDigital\AdminExportJobController;
use App\Http\Controllers\ArsipDigital\AdminRequestAssignmentController;
use App\Http\Controllers\ArsipDigital\AdminRequestController;
use App\Http\Controllers\ArsipDigital\AdminTargetController;
use App\Http\Controllers\ArsipDigital\ArchiveFileController;
use App\Http\Controllers\ArsipDigital\CategoryController;
use App\Http\Controllers\ArsipDigital\FoundationController;
use App\Http\Controllers\ArsipDigital\LecturerSignatureRequestController;
use App\Http\Controllers\ArsipDigital\NotificationController;
use App\Http\Controllers\ArsipDigital\PdfSelfSignController;
use App\Http\Controllers\ArsipDigital\ScholarshipController;
use App\Http\Controllers\ArsipDigital\SegmentController;
use App\Http\Controllers\ArsipDigital\SignatureRequestController;
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

        Route::get('/admin/settings', [FoundationController::class, 'settings']);
        Route::put('/admin/settings', [FoundationController::class, 'updateSettings']);

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

        Route::post('/pdf-sign-sessions', [PdfSelfSignController::class, 'store']);
        Route::post('/pdf-sign-sessions/{session_id}/finalize', [PdfSelfSignController::class, 'finalize']);
        Route::get('/pdf-sign-sessions/{session_id}', [PdfSelfSignController::class, 'show']);
        Route::get('/pdf-sign-sessions/{session_id}/download', [PdfSelfSignController::class, 'download']);
        Route::post('/pdf-sign-sessions/{session_id}/save', [PdfSelfSignController::class, 'save']);

        Route::get('/signature-request-config', [SignatureRequestController::class, 'config']);
        Route::get('/signature-request-lecturers', [SignatureRequestController::class, 'directory']);
        Route::get('/signature-requests', [SignatureRequestController::class, 'index']);
        Route::post('/signature-requests', [SignatureRequestController::class, 'store']);
        Route::get('/signature-requests/{signature_request_id}', [SignatureRequestController::class, 'show']);
        Route::put('/signature-requests/{signature_request_id}', [SignatureRequestController::class, 'update']);
        Route::delete('/signature-requests/{signature_request_id}', [SignatureRequestController::class, 'destroy']);
        Route::get('/signature-request-files/{signature_request_file_id}/{kind}', [SignatureRequestController::class, 'download'])->whereIn('kind', ['source', 'result']);
        Route::get('/lecturer/signature-request-availability', [LecturerSignatureRequestController::class, 'availability']);
        Route::put('/lecturer/signature-request-availability', [LecturerSignatureRequestController::class, 'setAvailability']);
        Route::post('/lecturer/signature-requests/bulk', [LecturerSignatureRequestController::class, 'bulk']);
        Route::post('/lecturer/signature-requests/{signature_request_id}/accept', [LecturerSignatureRequestController::class, 'accept']);
        Route::post('/lecturer/signature-requests/{signature_request_id}/reject', [LecturerSignatureRequestController::class, 'reject']);
        Route::post('/lecturer/signature-request-files/{signature_request_file_id}/session', [LecturerSignatureRequestController::class, 'createSession']);
        Route::post('/lecturer/signature-requests/{signature_request_id}/send', [LecturerSignatureRequestController::class, 'send']);

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
        Route::get('/distribution-files/{file_id}/download', [UserDistributionController::class, 'download']);

        Route::get('/admin/export-jobs', [AdminExportJobController::class, 'index']);
        Route::post('/admin/export-jobs', [AdminExportJobController::class, 'store']);
        Route::get('/admin/export-jobs/{export_job_id}', [AdminExportJobController::class, 'show']);
        Route::get('/admin/export-jobs/{export_job_id}/download', [AdminExportJobController::class, 'download']);

        Route::get('/admin/audit-logs', [AdminAuditLogController::class, 'index']);

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
