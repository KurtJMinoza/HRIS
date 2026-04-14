<?php

namespace App\Enums;

enum PolicyConditionKey: string
{
    case ORD = 'ORD';
    case RD = 'RD';
    case RH = 'RH';
    case RHRD = 'RHRD';
    case SH = 'SH';
    case SHRD = 'SHRD';
    case DH = 'DH';
    case DHRD = 'DHRD';

    public function label(): string
    {
        return match ($this) {
            self::ORD => 'Ordinary Day',
            self::RD => 'Rest Day',
            self::RH => 'Regular Holiday',
            self::RHRD => 'Regular Holiday + Rest Day',
            self::SH => 'Special Holiday',
            self::SHRD => 'Special Holiday + Rest Day',
            self::DH => 'Double Holiday',
            self::DHRD => 'Double Holiday + Rest Day',
        };
    }

    public static function all(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
