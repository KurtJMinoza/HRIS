<?php

namespace Tests\Unit;

use App\Models\Overtime;
use PHPUnit\Framework\TestCase;

class OvertimeDocumentPathsTest extends TestCase
{
    public function test_resolve_document_paths_merges_legacy_attachment_path(): void
    {
        $ot = new Overtime([
            'attachment_path' => 'overtime_attachments/legacy.pdf',
            'document_paths' => ['overtime_attachments/new-a.pdf', 'overtime_attachments/new-b.pdf'],
        ]);

        $this->assertSame(
            [
                'overtime_attachments/legacy.pdf',
                'overtime_attachments/new-a.pdf',
                'overtime_attachments/new-b.pdf',
            ],
            $ot->resolveDocumentPaths()
        );
    }

    public function test_resolve_document_paths_deduplicates(): void
    {
        $path = 'overtime_attachments/same.pdf';
        $ot = new Overtime([
            'attachment_path' => $path,
            'document_paths' => [$path],
        ]);

        $this->assertSame([$path], $ot->resolveDocumentPaths());
    }
}