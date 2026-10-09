<?php

namespace App\Models;

use Database\Factories\TravelClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A package client. Reused across bookings: match on phone or email before
 * creating a new one (see findOrCreateFor).
 */
#[Fillable(['name', 'email', 'phone', 'country', 'notes', 'created_by'])]
class TravelClient extends Model
{
    /** @use HasFactory<TravelClientFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (TravelClient $client): void {
            $client->phone_key = PropertyEngagementContact::phoneKey($client->phone);
            $client->email = $client->email ? mb_strtolower(trim($client->email)) : null;
        });
    }

    /**
     * @return HasMany<PackageBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(PackageBooking::class);
    }

    /**
     * The existing client with this phone or email, else a new one.
     *
     * @param  array{name: string, email?: ?string, phone?: ?string, country?: ?string}  $data
     */
    public static function findOrCreateFor(array $data, ?int $createdBy = null): self
    {
        $phoneKey = PropertyEngagementContact::phoneKey($data['phone'] ?? null);
        $email = filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null;

        $existing = static::query()
            ->where(fn (Builder $query) => $query
                ->when($phoneKey, fn (Builder $query) => $query->orWhere('phone_key', $phoneKey))
                ->when($email, fn (Builder $query) => $query->orWhere('email', $email)))
            ->when(! $phoneKey && ! $email, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->first();

        if ($existing) {
            $existing->fill(array_filter([
                'email' => $existing->email ?: $email,
                'phone' => $existing->phone ?: ($data['phone'] ?? null),
                'country' => $existing->country ?: ($data['country'] ?? null),
            ]))->save();

            return $existing;
        }

        return static::query()->create([
            'name' => $data['name'],
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'country' => $data['country'] ?? null,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Phone shown as 0712 *** 678 to people who should not see it in full.
     */
    public function maskedPhone(): ?string
    {
        return self::mask($this->phone);
    }

    public static function mask(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) < 7 ? '***' : substr($digits, 0, 4).' *** '.substr($digits, -3);
    }

    /**
     * @param  Builder<TravelClient>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $phoneKey = PropertyEngagementContact::phoneKey($term);

        $query->where(fn (Builder $query) => $query
            ->where('name', 'like', $like)
            ->orWhere('email', 'like', $like)
            ->when($phoneKey, fn (Builder $query) => $query->orWhere('phone_key', $phoneKey)));
    }
}
