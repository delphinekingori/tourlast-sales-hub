<?php

namespace App\Integrations\Tourlast;

use App\Enums\OnboardingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Turns a raw tourlast.com row or JSON object into a ProviderRecord, using
 * the value maps in config/tourlast.php.
 */
class ProviderRecordMapper
{
    /**
     * @param  array<string, mixed>  $row  Keys are Hub field names (property_id, ref_code, status...).
     */
    public function fromArray(array $row): ProviderRecord
    {
        $propertyId = trim((string) ($row['property_id'] ?? ''));

        if ($propertyId === '') {
            throw new InvalidArgumentException('A provider record is missing its property_id.');
        }

        return new ProviderRecord(
            propertyId: $propertyId,
            refCode: $this->refCode($row['ref_code'] ?? null),
            propertyName: $this->text($row['property_name'] ?? null) ?? 'Unnamed property',
            propertyType: $this->type($row['property_type'] ?? null),
            location: $this->text($row['location'] ?? null),
            contactName: $this->text($row['contact_name'] ?? null),
            contactPhone: $this->text($row['contact_phone'] ?? null),
            contactEmail: ($email = $this->text($row['contact_email'] ?? null)) ? Str::lower($email) : null,
            status: $this->status($row['status'] ?? null),
            submittedAt: $this->date($row['submitted_at'] ?? null),
            approvedAt: $this->date($row['approved_at'] ?? null),
            activeAt: $this->date($row['active_at'] ?? null),
            inactiveAt: $this->date($row['inactive_at'] ?? null),
            rejectedAt: $this->date($row['rejected_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
            raw: $row,
            accountId: $this->text($row['account_id'] ?? null),
            legalName: $this->text($row['legal_name'] ?? null),
            category: $this->category($row['category'] ?? null),
            inventoryCount: is_numeric($row['inventory_count'] ?? null) && (int) $row['inventory_count'] > 0 ? (int) $row['inventory_count'] : null,
            firstBookingAt: $this->date($row['first_booking_at'] ?? null),
            isDeleted: $this->deleted($row['is_deleted'] ?? null),
            deletedAt: $this->date($row['deleted_at'] ?? null),
        );
    }

    public function category(mixed $value): ?string
    {
        $key = Str::lower(trim((string) $value));

        return match (true) {
            in_array($key, ['stay', 'stays', 'accommodation', 'property', 'lodging'], true) => 'stay',
            in_array($key, ['experience', 'experiences', 'activity', 'tour', 'restaurant'], true) => 'experience',
            default => null,
        };
    }

    public function status(mixed $value): OnboardingStatus
    {
        $key = Str::lower(trim((string) $value));
        $mapped = config('tourlast.status_map.'.$key, $key);

        return OnboardingStatus::tryFrom((string) $mapped) ?? OnboardingStatus::Submitted;
    }

    /**
     * Anything the source app sends is kept as sent, after normalising it to a
     * slug. config/tourlast.php only lists aliases that should collapse onto an
     * existing Hub type ("guest house" -> guesthouse), so a type the Hub has
     * never seen still arrives intact instead of landing in "other".
     */
    public function type(mixed $value): string
    {
        $key = Str::of((string) $value)->lower()->trim()->replace([' ', '-', '/', '.'], '_')->toString();

        if ($key === '') {
            return 'other';
        }

        return (string) config('tourlast.type_map.'.$key, $key);
    }

    private function refCode(mixed $value): ?string
    {
        $code = Str::upper(trim((string) $value));

        return $code === '' ? null : $code;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * The deletion flag, accepting the usual spellings a source app might send.
     * Anything unrecognised counts as a live property, so a feed that has not
     * adopted the field keeps behaving exactly as it does today.
     */
    private function deleted(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
