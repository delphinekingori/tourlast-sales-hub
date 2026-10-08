<?php

namespace App\Enums\Travel;

/**
 * How a client paid.
 */
enum PaymentMethod: string
{
    case Mpesa = 'mpesa';
    case Bank = 'bank';
    case Cash = 'cash';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Mpesa => 'M-Pesa',
            self::Bank => 'Bank transfer',
            self::Cash => 'Cash',
            self::Card => 'Card',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Mpesa, self::Bank, self::Cash, self::Card => 'neutral',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn (self $case): string => $case->label(), self::cases()),
        );
    }
}
