<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UploadPurpose;
use App\Http\Controllers\Controller;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class UploadController extends Controller
{
    /**
     * Step 1 of attaching a file: upload it here, then send the returned id in the JSON save
     * (avatar_upload_id, work_experiences[].experience_letter_upload_id, documents[].upload_id).
     * Unused uploads expire after Upload::TTL_HOURS.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['purpose' => ['required', Rule::enum(UploadPurpose::class)]]);
        $purpose = UploadPurpose::from((string) $request->input('purpose'));
        $request->validate(['file' => ['required', 'file', ...$purpose->fileRules()]]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        /** @var User $user */
        $user = $request->user();

        $upload = Upload::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'path' => $file->store('uploads', 'local'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ]);

        return response()->json(['data' => [
            'id' => $upload->id,
            'purpose' => $upload->purpose->value,
            'original_name' => $upload->original_name,
            'mime_type' => $upload->mime_type,
            'size' => $upload->size,
            'expires_at' => $upload->created_at->copy()->addHours(Upload::TTL_HOURS)->toIso8601String(),
        ]], 201);
    }
}
