<?php

namespace Tests\Unit;

use App\Models\Attachment;
use PHPUnit\Framework\TestCase;

/** Adeline 11-24: a Monday asset stored without an extension downloads with its mime's. */
class AttachmentNameTest extends TestCase
{
    public function test_a_name_without_an_extension_takes_the_mimes(): void
    {
        $a = (new Attachment)->forceFill(['original_name' => 'IMG_2041', 'mime' => 'image/jpeg']);
        $this->assertSame('IMG_2041.jpg', $a->realName());
        $a->forceFill(['original_name' => 'photo.png']);
        $this->assertSame('photo.png', $a->realName());
    }
}
