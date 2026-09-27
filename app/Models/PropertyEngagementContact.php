<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['property_engagement_id', 'name', 'title', 'phone', 'whatsapp', 'email', 'is_primary', 'is_decision_maker', 'created_by'])]
class PropertyEngagementContact extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_decision_maker' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PropertyEngagementContact $contact): void {
            $contact->phone_key = self::phoneKey($contact->phone);
            $contact->email = filled($contact->email) ? strtolower(trim($contact->email)) : null;
        });
    }

    /**
     * @return BelongsTo<PropertyEngagement, $this>
     */
    public function propertyEngagement(): BelongsTo
    {
        return $this->belongsTo(PropertyEngagement::class);
    }

    /**
     * Last nine digits, so 0722…, +254722… and 254722… all match.
     */
    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 7 ? substr($digits, -9) : null;
    }
}
