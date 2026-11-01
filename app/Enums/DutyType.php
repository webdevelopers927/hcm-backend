<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DutyType: string
{
    use HasOptions;

    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
    case Freelance = 'freelance';
    case Internship = 'internship';
    case Temporary = 'temporary';
    case Consultant = 'consultant';

    public function label(): string
    {
        return match ($this) {
            self::FullTime => 'Full-time',
            self::PartTime => 'Part-time',
            default => ucfirst($this->value),
        };
    }
}
