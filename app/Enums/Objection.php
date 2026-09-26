<?php

namespace App\Enums;

/**
 * The main reason a property said no to Tourlast.
 */
enum Objection: string
{
    case Commission = 'commission';
    case Pricing = 'pricing';
    case OtherOta = 'other_ota';
    case HasPms = 'has_pms';
    case NoNeed = 'no_need';
    case ContractRestrictions = 'contract_restrictions';
    case Technical = 'technical';
    case ManagementApproval = 'management_approval';
    case Timing = 'timing';
    case CompetitorRelationship = 'competitor_relationship';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Commission => 'Commission',
            self::Pricing => 'Pricing',
            self::OtherOta => 'Already using another OTA',
            self::HasPms => 'Already has a PMS',
            self::NoNeed => 'No need',
            self::ContractRestrictions => 'Contract restrictions',
            self::Technical => 'Technical concerns',
            self::ManagementApproval => 'Management approval',
            self::Timing => 'Timing',
            self::CompetitorRelationship => 'Competitor relationship',
            self::Other => 'Other',
        };
    }

    /**
     * Objections that only make sense with the competitor named.
     */
    public function needsCompetitor(): bool
    {
        return in_array($this, [self::OtherOta, self::CompetitorRelationship], true);
    }

    /**
     * @return list<string>
     */
    public static function valuesNeedingCompetitor(): array
    {
        return array_values(array_map(fn (self $objection) => $objection->value, array_filter(self::cases(), fn (self $objection) => $objection->needsCompetitor())));
    }
}
