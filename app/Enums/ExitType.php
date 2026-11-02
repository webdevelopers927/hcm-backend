<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ExitType: string
{
    use HasOptions;

    case Resignation = 'resignation';
    case Termination = 'termination';
    case ContractEnd = 'contract_end';
    case Retirement = 'retirement';

    public function label(): string
    {
        return match ($this) {
            self::ContractEnd => 'Contract end',
            default => ucfirst($this->value),
        };
    }

    /** The exit type an exit status stands for ("Retired" → retirement); termination when unknown. */
    public static function forStatusName(string $name): self
    {
        foreach (self::cases() as $type) {
            if (strcasecmp($type->defaultFinalStatusName(), $name) === 0) {
                return $type;
            }
        }

        return self::Termination;
    }

    /** Name of the employment status an employee moves to after their last working day, by default. */
    public function defaultFinalStatusName(): string
    {
        return match ($this) {
            self::Resignation => 'Resigned',
            self::Termination => 'Terminated',
            self::Retirement => 'Retired',
            self::ContractEnd => 'Inactive',
        };
    }
}
