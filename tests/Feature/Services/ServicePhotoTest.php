<?php

namespace Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Service photos must be square. */
class ServicePhotoTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_a_square_photo_attaches_and_a_wide_one_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $service = $this->signedInAs($admin)
            ->postJson('/api/v1/services', [
                'company_id' => $tenant['company']->id,
                'name' => 'Sweep',
            ])
            ->assertCreated()
            ->json('data');

        $this->signedInAs($admin)
            ->post('/api/v1/attachments', [
                'attachable_type' => 'services',
                'attachable_id' => $service['id'],
                'file' => UploadedFile::fake()->image('wide.jpg', 200, 100),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The photo has to be square (1:1).');

        $this->signedInAs($admin)
            ->post('/api/v1/attachments', [
                'attachable_type' => 'services',
                'attachable_id' => $service['id'],
                'file' => UploadedFile::fake()->image('square.jpg', 200, 200),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->signedInAs($admin)
            ->getJson('/api/v1/services/'.$service['id'])
            ->assertOk()
            ->assertJsonPath('data.photo.mime', 'image/jpeg');

        $this->signedInAs($admin)
            ->post('/api/v1/attachments', [
                'attachable_type' => 'services',
                'attachable_id' => $service['id'],
                'file' => UploadedFile::fake()->image('again.jpg', 100, 100),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This service already has a photo. Remove it first.');
    }

    public function test_replace_keeps_the_old_photo_until_the_new_one_is_stored(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        $id = $this->signedInAs($admin)
            ->postJson('/api/v1/services', ['company_id' => $tenant['company']->id, 'name' => 'Sweep'])
            ->assertCreated()->json('data.id');
        $up = fn ($file, $replace) => $this->signedInAs($admin)->post('/api/v1/attachments', array_filter([
            'attachable_type' => 'services', 'attachable_id' => $id, 'file' => $file, 'replace' => $replace,
        ]), ['Accept' => 'application/json']);

        $first = $up(UploadedFile::fake()->image('a.jpg', 200, 200), null)->assertCreated()->json('data.id');

        // A failed replacement (not square) leaves the first photo in place.
        $up(UploadedFile::fake()->image('wide.jpg', 200, 100), '1')->assertStatus(422);
        $this->signedInAs($admin)->getJson('/api/v1/services/'.$id)->assertJsonPath('data.photo.id', $first);

        // A good one replaces it: exactly one photo, and it is the new one.
        $second = $up(UploadedFile::fake()->image('b.png', 300, 300), '1')->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);
        $this->signedInAs($admin)->getJson('/api/v1/services/'.$id)->assertJsonPath('data.photo.id', $second);
        $this->inTenant($tenant['org'], fn () => $this->assertSame(1, \App\Models\Attachment::query()
            ->where('attachable_type', 'services')->where('attachable_id', $id)->count()));
    }
}
