<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\OfficialDocument;

class OfficialDocumentNumberService
{
    public function suggest(string $documentType): string
    {
        $latest = OfficialDocument::where('document_type', $documentType)
            ->orderByDesc('issued_at')
            ->orderByDesc('official_document_id')
            ->value('document_number');

        if (! $latest || ! preg_match('/^(.*?)(\d+)$/', $latest, $matches)) {
            return '001';
        }

        $next = (int) $matches[2] + 1;

        return $matches[1].str_pad((string) $next, strlen($matches[2]), '0', STR_PAD_LEFT);
    }
}
