<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum MaritalStatus: string
{
    use HasOptions;

    case Single = 'single';
    case Married = 'married';
    case Divorced = 'divorced';
    case Widowed = 'widowed';
    case Separated = 'separated';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
