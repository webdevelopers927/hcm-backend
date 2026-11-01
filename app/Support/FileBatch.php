<?php

namespace App\Support;

use App\Models\Upload;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Keeps files in step with a DB transaction. Uploads are copied into place during the transaction;
 * if it fails the copies are removed (the uploads stay, so the user can retry). Files being replaced or
 * removed, and the consumed uploads, are deleted only after commit.
 */
final class FileBatch
{
    /** @var list<array{0: string, 1: string}> disk, path */
    private array $created = [];

    /** @var list<array{0: string, 1: string}> */
    private array $obsolete = [];

    /** @var array<string, Upload> */
    private array $consumed = [];

    /**
     * Runs $callback (which receives the batch) in a DB transaction and settles the files afterwards.
     *
     * @template T
     *
     * @param  Closure(self): T  $callback
     * @return T
     */
    public static function transaction(Closure $callback): mixed
    {
        $batch = new self;

        try {
            $result = DB::transaction(fn () => $callback($batch));
        } catch (Throwable $e) {
            $batch->rollback();

            throw $e;
        }

        $batch->commit();

        return $result;
    }

    /**
     * Copies an upload to its permanent place and returns the stored file's details.
     *
     * @return array{path: string, name: string, mime_type: string, size: int}
     */
    public function claim(string $uploadId, string $disk, string $directory): array
    {
        $upload = Upload::query()->findOrFail($uploadId);

        $extension = pathinfo($upload->original_name, PATHINFO_EXTENSION);
        $path = trim($directory, '/').'/'.Str::uuid().($extension !== '' ? '.'.strtolower($extension) : '');

        $stream = Storage::disk('local')->readStream($upload->path);
        if ($stream === null || ! Storage::disk($disk)->writeStream($path, $stream)) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }
        if (is_resource($stream)) {
            fclose($stream);
        }

        $this->created[] = [$disk, $path];
        $this->consumed[$upload->id] = $upload;

        return [
            'path' => $path,
            'name' => $upload->original_name,
            'mime_type' => $upload->mime_type,
            'size' => $upload->size,
        ];
    }

    /** Deletes a stored file once the transaction has committed. */
    public function deleteAfterCommit(string $disk, ?string $path): void
    {
        if ($path !== null && $path !== '') {
            $this->obsolete[] = [$disk, $path];
        }
    }

    private function commit(): void
    {
        foreach ($this->obsolete as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        foreach ($this->consumed as $upload) {
            Storage::disk('local')->delete($upload->path);
            $upload->delete();
        }
    }

    private function rollback(): void
    {
        foreach ($this->created as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
    }
}
