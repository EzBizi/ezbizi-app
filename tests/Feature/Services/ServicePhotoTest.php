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
}
