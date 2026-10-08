<?php

namespace App\Enums\Travel;

/**
 * How a payment reached the Hub.
 */
enum PaymentChannel: string
{
    case Stk = 'stk';
    case Paybill = 'paybill';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Stk => 'M-Pesa request to phone',
            self::Paybill => 'Paid to paybill',
            self::Manual => 'Recorded by staff',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Stk, self::Paybill, self::Manual => 'neutral',
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
