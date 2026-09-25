<?php

namespace Tests\Unit;

use App\Services\PresenceFilingService;
use Tests\TestCase;

class PresenceFilingSupportingDocumentsTest extends TestCase
{
    public function test_forgot_punch_does_not_require_documents(): void
    {
        $this->assertFalse(PresenceFilingService::requiresSupportingDocument(PresenceFilingService::REASON_FORGOT_PUNCH));
    }

    public function test_field_work_requires_documents(): void
    {
        $this->assertTrue(PresenceFilingService::requiresSupportingDocument(PresenceFilingService::REASON_FIELD_WORK));
    }

    public function test_reason_options_expose_requires_document_flag(): void
    {
        $options = PresenceFilingService::reasonOptionsForApi();
        $byValue = collect($options)->keyBy('value');

        $this->assertFalse($byValue[PresenceFilingService::REASON_FORGOT_PUNCH]['requires_document']);
        $this->assertTrue($byValue[PresenceFilingService::REASON_SYSTEM_ISSUE]['requires_document']);
    }

    public function test_serialize_supporting_documents_uses_api_media_url(): void
    {
        $correction = new \App\Models\AttendanceCorrection([
            'document_paths' => ['attendance-correction-documents/sample.pdf'],
        ]);

        $docs = app(PresenceFilingService::class)->serializeSupportingDocuments($correction);
        $this->assertCount(1, $docs);
        $this->assertStringContainsString('/api/media/public/', $docs[0]['url']);
        $this->assertSame('sample.pdf', $docs[0]['filename']);
    }
}
