<?php

namespace App\Models;

use App\Enums\Role;
use App\Events\AnnouncementPublished;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A notice from admins, Sales Managers, HR or Finance to some or all of the team.
 */
#[Fillable(['user_id', 'title', 'body', 'audience', 'importance'])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    /**
     * Audience groups and the roles in each.
     *
     * @var array<string, array{label: string, roles: list<Role>}>
     */
    public const Audiences = [
        'everyone' => ['label' => 'Everyone', 'roles' => []],
        'sales' => ['label' => 'Salespeople', 'roles' => [Role::Salesperson]],
        'management' => ['label' => 'Managers & admins', 'roles' => [Role::SuperAdmin, Role::SalesAdmin, Role::SalesManager]],
        'hr' => ['label' => 'HR', 'roles' => [Role::Hr]],
        'finance' => ['label' => 'Finance', 'roles' => [Role::Accounts]],
    ];

    protected static function booted(): void
    {
        static::created(fn (Announcement $announcement) => AnnouncementPublished::dispatch($announcement));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<AnnouncementRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    public function audienceLabel(): string
    {
        return collect($this->audience)->map(fn (string $key): string => self::Audiences[$key]['label'] ?? $key)->implode(', ');
    }

    public function isFor(User $user): bool
    {
        if (in_array('everyone', $this->audience, true) || $user->is($this->author)) {
            return true;
        }

        $role = $user->role();

        foreach ($this->audience as $key) {
            if (in_array($role, self::Audiences[$key]['roles'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Announcements addressed to the user's role (or to everyone), plus their own.
     *
     * @param  Builder<Announcement>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $role = $user->role();
        $keys = ['everyone'];

        foreach (self::Audiences as $key => $group) {
            if (in_array($role, $group['roles'], true)) {
                $keys[] = $key;
            }
        }

        $query->where(function (Builder $query) use ($keys, $user): void {
            $query->where('user_id', $user->id);

            foreach ($keys as $key) {
                $query->orWhereJsonContains('audience', $key);
            }
        });
    }

    /**
     * @param  Builder<Announcement>  $query
     */
    #[Scope]
    protected function unreadBy(Builder $query, User $user): void
    {
        $query->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $user->id))->where('user_id', '!=', $user->id);
    }
}
