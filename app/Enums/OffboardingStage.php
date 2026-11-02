<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Board columns, in order. */
enum OffboardingStage: string
{
    use HasOptions;

    case Resigned = 'resigned';
    case NoticePeriod = 'notice_period';
    case Clearance = 'clearance';
    case ExitInterview = 'exit_interview';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::NoticePeriod => 'Notice period',
            self::ExitInterview => 'Exit interview',
            default => ucfirst($this->value),
        };
    }
}
