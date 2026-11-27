<?php

namespace Tests\Unit;

use App\Support\Ai\AppAsk;
use PHPUnit\Framework\TestCase;

/** Jose, 2026-11-09: techs get job photos and clips, only ones their own reads returned. */
class AppAskMediaTest extends TestCase
{
    public function test_only_a_photo_or_mp4_link_the_data_held_is_sent(): void
    {
        $data = json_encode(['jobs/photos' => ['data' => [['photos' => [
            'https://st.sendajob.com/a/before.jpg', 'https://st.sendajob.com/a/after.png', 'https://st.sendajob.com/a/report.pdf',
        ]]]]], JSON_UNESCAPED_SLASHES);

        $this->assertSame(
            ['https://st.sendajob.com/a/before.jpg', 'https://st.sendajob.com/a/after.png'],
            AppAsk::mediaFrom([
                'https://st.sendajob.com/a/before.jpg',
                'https://st.sendajob.com/a/after.png',
                'https://st.sendajob.com/a/report.pdf',      // not a photo
                'https://evil.example/other-org.jpg',         // never read by this person
                'http://st.sendajob.com/a/before.jpg',        // not https
            ], $data),
        );
    }
}
