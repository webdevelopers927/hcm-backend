<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    case Active = 'active';
    case Probation = 'probation';
    case OnLeave = 'on_leave';
    case NoticePeriod = 'notice_period';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Probation => 'Probation',
            self::OnLeave => 'On leave',
            self::NoticePeriod => 'Notice period',
            self::Inactive => 'Inactive',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
