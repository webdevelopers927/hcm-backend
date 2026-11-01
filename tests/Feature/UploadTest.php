<?php

use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    actingAsUser();
    Storage::fake('local');
});

it('stores an upload privately and returns its id', function () {
    $response = $this->post('/api/v1/uploads', [
        'purpose' => 'document',
        'file' => UploadedFile::fake()->create('cnic-copy.pdf', 300, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $upload = Upload::findOrFail($response->json('data.id'));
    expect($response->json('data.original_name'))->toBe('cnic-copy.pdf')
        ->and($response->json('data.purpose'))->toBe('document')
        ->and($response->json('data.expires_at'))->not->toBeNull();
    Storage::disk('local')->assertExists($upload->path);
});

it('checks the file type and size for each purpose', function (string $purpose, Closure $file) {
    $this->post('/api/v1/uploads', ['purpose' => $purpose, 'file' => $file()], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
})->with([
    'avatar must be an image' => ['avatar', fn () => UploadedFile::fake()->create('me.pdf', 10, 'application/pdf')],
    'avatar max 2 MB' => ['avatar', fn () => UploadedFile::fake()->image('big.jpg')->size(3000)],
    'document type' => ['document', fn () => UploadedFile::fake()->create('run.exe', 10, 'application/octet-stream')],
    'document max 10 MB' => ['document', fn () => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')],
]);

it('requires a known purpose', function () {
    $this->post('/api/v1/uploads', ['purpose' => 'selfie', 'file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['purpose']);
});

it('prunes uploads that were never attached', function () {
    $old = Upload::findOrFail(uploadFile('document'));
    $fresh = Upload::findOrFail(uploadFile('document'));
    $old->forceFill(['created_at' => now()->subHours(Upload::TTL_HOURS + 1)])->save();

    $this->artisan('uploads:prune')->assertSuccessful();

    expect(Upload::find($old->id))->toBeNull()->and(Upload::find($fresh->id))->not->toBeNull();
    Storage::disk('local')->assertMissing($old->path);
});
