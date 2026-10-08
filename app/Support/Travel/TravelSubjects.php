<?php

namespace App\Support\Travel;

use App\Models\FlightBooking;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\TravelClient;
use App\Models\TravelProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * The travel records a calendar item or follow-up can be about, written as
 * "kind:id" (e.g. "package:12") in forms and the open-schedule event.
 */
class TravelSubjects
{
    /** @var array<string, array{class: class-string<Model>, label: string}> */
    public const Kinds = [
        'provider' => ['class' => TravelProvider::class, 'label' => 'Provider'],
        'package' => ['class' => Package::class, 'label' => 'Package'],
        'booking' => ['class' => PackageBooking::class, 'label' => 'Booking'],
        'client' => ['class' => TravelClient::class, 'label' => 'Client'],
        'flight' => ['class' => FlightBooking::class, 'label' => 'Flight booking'],
    ];

    public static function key(Model $subject): string
    {
        foreach (self::Kinds as $kind => $definition) {
            if ($subject instanceof $definition['class']) {
                return $kind.':'.$subject->getKey();
            }
        }

        throw new \InvalidArgumentException('Not a travel record: '.$subject::class);
    }

    /**
     * The record behind "kind:id" if the user may see it.
     */
    public static function find(?string $key, User $user): ?Model
    {
        if (! is_string($key) || ! preg_match('/^([a-z]+):(\d+)$/', $key, $match) || ! isset(self::Kinds[$match[1]])) {
            return null;
        }

        return self::visibleQuery($match[1], $user)->find((int) $match[2]);
    }

    public static function label(?Model $subject): string
    {
        return match (true) {
            $subject instanceof TravelProvider => $subject->name,
            $subject instanceof Package => $subject->name,
            $subject instanceof PackageBooking => $subject->reference.($subject->client ? ' · '.$subject->client->name : ''),
            $subject instanceof TravelClient => $subject->name,
            $subject instanceof FlightBooking => ($subject->booking_reference ?? $subject->external_id).' · '.$subject->route(),
            default => 'Travel item',
        };
    }

    public static function kindLabel(?Model $subject): string
    {
        foreach (self::Kinds as $kind) {
            if ($subject instanceof $kind['class']) {
                return $kind['label'];
            }
        }

        return 'Travel';
    }

    public static function url(?Model $subject): ?string
    {
        [$route, $parameter] = match (true) {
            $subject instanceof TravelProvider => ['travel.providers.show', $subject],
            $subject instanceof Package => ['travel.packages.show', $subject],
            $subject instanceof PackageBooking => ['travel.bookings.show', $subject],
            $subject instanceof FlightBooking => ['travel.flights.show', $subject],
            $subject instanceof TravelClient => ['travel.clients.index', null],
            default => [null, null],
        };

        if (! $route || ! Route::has($route)) {
            return null;
        }

        return $parameter ? route($route, $parameter) : route($route);
    }

    /**
     * Choices for the schedule form, grouped by kind.
     *
     * @return array<string, array<string, string>> kind label => [key => label]
     */
    public static function options(User $user, ?string $selected = null): array
    {
        $groups = [];

        foreach (self::Kinds as $kind => $definition) {
            $records = self::visibleQuery($kind, $user)
                ->when($kind === 'booking', fn (Builder $query) => $query->with('client:id,name')->latest()->limit(100))
                ->when($kind === 'flight', fn (Builder $query) => $query->latest('booked_at')->limit(100))
                ->when(in_array($kind, ['provider', 'package', 'client'], true), fn (Builder $query) => $query->orderBy('name')->limit(200))
                ->get();

            foreach ($records as $record) {
                $groups[$definition['label']][self::key($record)] = self::label($record);
            }
        }

        $chosen = self::find($selected, $user);

        if ($chosen) {
            $groups[self::kindLabel($chosen)][self::key($chosen)] = self::label($chosen);
        }

        return $groups;
    }

    /**
     * @return Builder<Model>
     */
    private static function visibleQuery(string $kind, User $user): Builder
    {
        $managesAll = TravelAccess::managesAll($user);

        return match ($kind) {
            'provider' => TravelProvider::query()->current(),
            'package' => Package::query()->current()->when(! $managesAll, fn (Builder $query) => $query->where('owner_id', $user->id)),
            'booking' => PackageBooking::query()->visibleTo($user),
            'client' => TravelClient::query()->when(! $managesAll, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('created_by', $user->id)
                ->orWhereHas('bookings', fn (Builder $bookings) => $bookings->visibleTo($user)))),
            'flight' => FlightBooking::query()->when(! $managesAll, fn (Builder $query) => $query->where('salesperson_id', $user->id)),
        };
    }
}
