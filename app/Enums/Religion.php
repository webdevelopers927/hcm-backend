<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Religion: string
{
    use HasOptions;

    case Islam = 'islam';
    case Christianity = 'christianity';
    case Hinduism = 'hinduism';
    case Sikhism = 'sikhism';
    case Buddhism = 'buddhism';
    case Judaism = 'judaism';
    case Other = 'other';
    case PreferNotToSay = 'prefer_not_to_say';

    public function label(): string
    {
        return match ($this) {
            self::PreferNotToSay => 'Prefer not to say',
            default => ucfirst($this->value),
        };
    }
}
