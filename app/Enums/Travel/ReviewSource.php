<?php

namespace App\Enums\Travel;

/**
 * How the client's feedback reached us (staff record it on the client's behalf).
 */
enum ReviewSource: string
{
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Form = 'form';
    case InPerson = 'in_person';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone call',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Form => 'Feedback form',
            self::InPerson => 'In person',
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
