<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Salutation: string
{
    use HasOptions;

    case Mr = 'mr';
    case Mrs = 'mrs';
    case Ms = 'ms';
    case Miss = 'miss';
    case Dr = 'dr';
    case Prof = 'prof';
    case Engr = 'engr';

    public function label(): string
    {
        return match ($this) {
            self::Mr => 'Mr.',
            self::Mrs => 'Mrs.',
            self::Ms => 'Ms.',
            self::Miss => 'Miss',
            self::Dr => 'Dr.',
            self::Prof => 'Prof.',
            self::Engr => 'Engr.',
        };
    }
}
