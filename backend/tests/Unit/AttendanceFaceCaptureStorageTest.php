<?php

namespace Tests\Unit;

use App\Support\AttendanceFaceCaptureStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceFaceCaptureStorageTest extends TestCase
{
    public function test_store_and_read_data_url_round_trip(): void
    {
        Storage::fake('local');
        $binary = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA//2Q==', true);
        $encoded = base64_encode($binary);

        $path = AttendanceFaceCaptureStorage::store(42, 99, $encoded);
        $this->assertNotNull($path);
        $dataUrl = AttendanceFaceCaptureStorage::toDataUrl($path);
        $this->assertNotNull($dataUrl);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $dataUrl);
    }
}
