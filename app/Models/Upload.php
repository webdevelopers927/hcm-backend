<?php

namespace App\Models;

use App\Enums\UploadPurpose;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A file waiting to be attached by a JSON save (see AttachUploads). Stored on the private local disk.
 *
 * @property string $id
 * @property int $user_id
 * @property UploadPurpose $purpose
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property Carbon $created_at
 */
class Upload extends Model
{
    use HasUuids;

    /** Unattached uploads older than this are pruned. */
    public const TTL_HOURS = 24;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'purpose', 'path', 'original_name', 'mime_type', 'size'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => UploadPurpose::class,
            'size' => 'integer',
        ];
    }
}
