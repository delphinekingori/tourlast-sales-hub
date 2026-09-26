<?php

namespace App\Enums;

enum EngagementSource: string
{
    case ColdCall = 'cold_call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Website = 'website';
    case Referral = 'referral';
    case FieldVisit = 'field_visit';
    case TradeShow = 'trade_show';
    case TourismEvent = 'tourism_event';
    case ExistingPartner = 'existing_partner';
    case Campaign = 'campaign';
    case Prospecting = 'prospecting';
    case ManagementIntroduction = 'management_introduction';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ColdCall => 'Cold call',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Website => 'Website',
            self::Referral => 'Referral',
            self::FieldVisit => 'Field visit',
            self::TradeShow => 'Trade show',
            self::TourismEvent => 'Tourism event',
            self::ExistingPartner => 'Existing partner',
            self::Campaign => 'Tourlast campaign',
            self::Prospecting => 'Salesperson prospecting',
            self::ManagementIntroduction => 'Management introduction',
            self::Other => 'Other',
        };
    }
}
