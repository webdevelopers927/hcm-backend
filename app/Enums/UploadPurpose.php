<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum UploadPurpose: string
{
    use HasOptions;

    case Avatar = 'avatar';
    case ExperienceLetter = 'experience_letter';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Avatar => 'Profile picture',
            self::ExperienceLetter => 'Experience letter',
            self::Document => 'Document',
        };
    }

    /**
     * Validation rules for the uploaded file.
     *
     * @return list<string>
     */
    public function fileRules(): array
    {
        return match ($this) {
            self::Avatar => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
            self::ExperienceLetter, self::Document => ['mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:10240'],
        };
    }
}
