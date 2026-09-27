<?php

namespace App\Support;

use App\Models\PropertyEngagement;
use App\Models\PropertyEngagementContact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Normalised identifiers of a property being entered, and the shared rules
 * for deciding whether an existing record looks like the same business.
 */
final readonly class MatchKeys
{
    /**
     * @param  list<list<string>>  $nameSets  Distinctive words of the name and of the trading name.
     * @param  list<string>  $inputNames  The name and trading name, normalised.
     * @param  list<string>  $phones  Last nine digits.
     * @param  list<string>  $emails  Lower-cased.
     */
    public function __construct(
        public array $nameSets,
        public array $inputNames,
        public ?string $exactName,
        public string $city,
        public array $phones,
        public array $emails,
        public ?string $website,
        public ?string $registration,
        public ?string $kraPin,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input): self
    {
        $nameSets = array_values(array_filter([
            DuplicateEngagementFinder::significantTokens($input['name'] ?? ''),
            DuplicateEngagementFinder::significantTokens($input['trading_name'] ?? ''),
        ]));
        $nameKey = PropertyEngagement::nameKey($input['name'] ?? '');

        return new self(
            nameSets: $nameSets,
            inputNames: array_values(array_filter([$nameKey, PropertyEngagement::nameKey($input['trading_name'] ?? '')])),
            // Names made only of common words ("Beach Hotel") must match exactly.
            exactName: $nameSets === [] && strlen($nameKey) >= 4 ? $nameKey : null,
            city: PropertyEngagement::nameKey($input['city'] ?? ''),
            phones: collect($input['phones'] ?? [])->map(fn ($phone) => PropertyEngagementContact::phoneKey($phone))->filter()->unique()->values()->all(),
            emails: collect($input['emails'] ?? [])->filter()->map(fn ($email) => strtolower(trim((string) $email)))->unique()->values()->all(),
            website: PropertyEngagement::websiteKey($input['website'] ?? null),
            registration: filled($input['registration_number'] ?? null) ? trim((string) $input['registration_number']) : null,
            kraPin: filled($input['kra_pin'] ?? null) ? strtoupper(trim((string) $input['kra_pin'])) : null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->nameSets === [] && $this->exactName === null && $this->phones === [] && $this->emails === []
            && ! $this->website && ! $this->registration && ! $this->kraPin;
    }

    /**
     * Candidate rows: any distinctive word appears in the column. The exact
     * similarity rule is applied afterwards in reasons().
     *
     * @param  Builder<Model>  $query
     * @param  list<string>  $tokens
     */
    public function matchName(Builder $query, array $tokens, string $column): void
    {
        foreach ($tokens as $token) {
            $query->orWhere($column, 'like', '%'.$token.'%');
        }
    }

    /**
     * Why an existing record looks like this property (empty when it doesn't).
     *
     * @param  list<string>  $phones
     * @param  list<string>  $emails
     * @return list<string>
     */
    public function reasons(string $name, string $city, array $phones = [], array $emails = [], ?string $website = null, ?string $registration = null, ?string $kraPin = null): array
    {
        $reasons = [];
        // $name may hold several names separated by " | " (name | trading name).
        $existingNames = array_values(array_filter(array_map(fn (string $part) => PropertyEngagement::nameKey($part), explode(' | ', $name))));
        $nameKey = implode(' ', $existingNames);

        // Similar when every distinctive word typed is in the existing name, or
        // every distinctive word of an existing name is in what was typed
        // ("Tembo Lodge Naivasha" ↔ "Tembo Lodge").
        $nameMatches = collect($this->nameSets)->contains(fn (array $tokens) => collect($tokens)->every(fn (string $token) => str_contains($nameKey, $token)))
            || collect($existingNames)->contains(function (string $existing): bool {
                $tokens = DuplicateEngagementFinder::significantTokens($existing);

                return $tokens !== [] && collect($this->inputNames)->contains(fn (string $typed) => collect($tokens)->every(fn (string $token) => str_contains(' '.$typed.' ', ' '.$token.' ')));
            })
            || ($this->exactName !== null && str_contains(' '.$nameKey.' ', ' '.$this->exactName.' '));

        if ($nameMatches) {
            $reasons[] = $this->city !== '' && str_contains(PropertyEngagement::nameKey($city), $this->city) ? 'Similar name, same town' : 'Similar name';
        }

        if ($this->phones !== [] && array_intersect($this->phones, array_map(fn ($phone) => PropertyEngagementContact::phoneKey($phone) ?? $phone, $phones))) {
            $reasons[] = 'Same phone number';
        }

        if ($this->emails !== [] && array_intersect($this->emails, array_map(fn ($email) => strtolower((string) $email), $emails))) {
            $reasons[] = 'Same email';
        }

        if ($this->website && $website === $this->website) {
            $reasons[] = 'Same website';
        }

        if ($this->registration && $registration === $this->registration) {
            $reasons[] = 'Same registration number';
        }

        if ($this->kraPin && strtoupper((string) $kraPin) === $this->kraPin) {
            $reasons[] = 'Same KRA PIN';
        }

        return $reasons;
    }

    /**
     * Stronger evidence first: identifiers beat a similar name.
     *
     * @param  list<string>  $reasons
     */
    public static function score(array $reasons): int
    {
        return collect($reasons)->sum(fn (string $reason) => match ($reason) {
            'Similar name' => 5,
            'Similar name, same town' => 8,
            default => 10,
        });
    }
}
