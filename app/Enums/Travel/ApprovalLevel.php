<?php

namespace App\Enums\Travel;

/**
 * Which review an approval decision belongs to.
 */
enum ApprovalLevel: string
{
    case SalesAdmin = 'sales_admin';
    case SuperAdmin = 'super_admin';
    case ContractOverride = 'contract_override';

    public function label(): string
    {
        return match ($this) {
            self::SalesAdmin => 'Sales Admin review',
            self::SuperAdmin => 'Super Admin review',
            self::ContractOverride => 'Contract override',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::SalesAdmin, self::SuperAdmin => 'neutral',
            self::ContractOverride => 'warning',
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
