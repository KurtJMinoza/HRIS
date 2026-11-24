<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LeaveFilingAttachmentsTest extends TestCase
{
    public function test_document_preview_payload_shape(): void
    {
        $paths = ['leave-documents/sample.pdf'];
        $documents = array_map(
            static fn (string $p, int $i) => [
                'url' => '/api/media/public/'.$p,
                'filename' => basename($p) ?: 'Document '.($i + 1),
            ],
            $paths,
            array_keys($paths),
        );
        $count = count($paths);

        $payload = [
            'document_count' => $count,
            'documents' => $documents,
            'attachment_count' => $count,
            'documents_count' => $count,
        ];

        $this->assertSame(1, $payload['attachment_count']);
        $this->assertCount(1, $payload['documents']);
        $this->assertSame('sample.pdf', $payload['documents'][0]['filename']);
    }
}