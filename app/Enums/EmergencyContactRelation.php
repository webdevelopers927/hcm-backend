<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum EmergencyContactRelation: string
{
    use HasOptions;

    case Father = 'father';
    case Mother = 'mother';
    case Spouse = 'spouse';
    case Brother = 'brother';
    case Sister = 'sister';
    case Son = 'son';
    case Daughter = 'daughter';
    case Guardian = 'guardian';
    case Relative = 'relative';
    case Friend = 'friend';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
