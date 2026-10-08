<?php

namespace App\Support;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the system-wide audit log. Call from actions after a change is saved:
 *
 *     Audit::record($package, 'package.approved', 'Approved v1.1 (Sales Admin review)');
 *     Audit::record($version, 'package.price_changed', 'Adult price changed', Audit::diff($old, $new));
 */
class Audit
{
    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [old, new]
     */
    public static function record(?Model $subject, string $action, string $summary, array $changes = [], ?int $userId = null): AuditEvent
    {
        return AuditEvent::query()->create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'summary' => mb_substr($summary, 0, 255),
            'changes' => $changes === [] ? null : $changes,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Field-by-field differences between two attribute arrays, for record().
     * Only keys present in $after are compared.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            $old = $before[$field] ?? null;

            if (self::normalise($old) !== self::normalise($value)) {
                $changes[$field] = [$old, $value];
            }
        }

        return $changes;
    }

    private static function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_numeric($value) => (string) (float) $value,
            $value === '' => null,
            default => $value,
        };
    }
}
