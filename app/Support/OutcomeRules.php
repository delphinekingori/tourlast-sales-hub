<?php

namespace App\Support;

use App\Enums\Objection;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * Validation and parsing for "why did they say no" (objection, competitor,
 * notes, re-engage date), shared by leads and the registry.
 */
class OutcomeRules
{
    /**
     * @param  string  $prefix  e.g. "lost." or "log." — the Livewire property path.
     * @param  bool  $required  Whether an objection must be given (marking Lost / Rejected).
     * @param  ?string  $objection  The objection currently chosen, to decide if a competitor is needed.
     * @return array<string, mixed>
     */
    public static function rules(string $prefix, bool $required, ?string $objection, string $notesKey = 'notes'): array
    {
        return [
            $prefix.'objection' => [Rule::requiredIf($required), 'nullable', Rule::enum(Objection::class)],
            $prefix.'competitor' => [Rule::requiredIf(in_array($objection, Objection::valuesNeedingCompetitor(), true)), 'nullable', 'string', 'max:100'],
            $prefix.$notesKey => ['nullable', 'string', 'max:5000'],
            $prefix.'reengage_on' => ['nullable', 'date', 'after:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(string $prefix, string $notesKey = 'notes'): array
    {
        return [
            $prefix.'objection' => 'primary objection',
            $prefix.'competitor' => 'competitor',
            $prefix.$notesKey => 'notes',
            $prefix.'reengage_on' => 're-engage date',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{objection: ?Objection, competitor: ?string, notes: ?string, reengage_on: ?CarbonImmutable}
     */
    public static function parse(array $data, string $notesKey = 'notes'): array
    {
        return [
            'objection' => Objection::tryFrom((string) ($data['objection'] ?? '')),
            'competitor' => filled($data['competitor'] ?? null) ? trim((string) $data['competitor']) : null,
            'notes' => filled($data[$notesKey] ?? null) ? trim((string) $data[$notesKey]) : null,
            'reengage_on' => filled($data['reengage_on'] ?? null) ? CarbonImmutable::parse($data['reengage_on']) : null,
        ];
    }
}
