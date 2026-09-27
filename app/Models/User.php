<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name', 'email', 'phone', 'region', 'is_active', 'account_status', 'suspended_until', 'status_changed_at',
    'password', 'last_login_at', 'last_seen_at',
    'avatar_path', 'job_title', 'bio', 'emergency_contact_name', 'emergency_contact_phone',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
            'account_status' => AccountStatus::class,
            'suspended_until' => 'date',
            'status_changed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<ReferralCode, $this>
     */
    public function referralCodes(): HasMany
    {
        return $this->hasMany(ReferralCode::class);
    }

    /**
     * The code the salesperson currently shares.
     *
     * @return HasOne<ReferralCode, $this>
     */
    public function referralCode(): HasOne
    {
        return $this->hasOne(ReferralCode::class)->where('is_active', true)->latestOfMany();
    }

    /**
     * Onboardings credited to this salesperson.
     *
     * @return HasMany<Onboarding, $this>
     */
    public function onboardings(): HasMany
    {
        return $this->hasMany(Onboarding::class);
    }

    /**
     * @return HasMany<IncentiveAgreement, $this>
     */
    public function incentiveAgreements(): HasMany
    {
        return $this->hasMany(IncentiveAgreement::class);
    }

    /**
     * @return HasMany<PointEntry, $this>
     */
    public function pointEntries(): HasMany
    {
        return $this->hasMany(PointEntry::class);
    }

    /**
     * @return HasMany<Target, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(Target::class);
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Suspensions, terminations and reinstatements, newest first.
     *
     * @return HasMany<UserStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(UserStatusChange::class)->latest()->latest('id');
    }

    /**
     * What a suspended or fired person sees when they try to sign in.
     */
    public function inactiveMessage(): string
    {
        return match ($this->accountStatus()) {
            AccountStatus::Suspended => 'This account is suspended'.($this->suspended_until ? ' until '.$this->suspended_until->format('j M Y') : '').'. Contact your Sales Manager.',
            AccountStatus::Terminated => 'This account is no longer active. Contact your Sales Admin.',
            AccountStatus::Active => 'This account has been deactivated. Contact your Sales Admin.',
        };
    }

    public function accountStatus(): AccountStatus
    {
        return $this->account_status ?? ($this->is_active ? AccountStatus::Active : AccountStatus::Suspended);
    }

    /**
     * @return HasMany<FollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function sentInvitations(): HasMany
    {
        return $this->hasMany(Invitation::class, 'invited_by');
    }

    /**
     * The user's role. Each user holds exactly one.
     */
    public function role(): ?Role
    {
        $name = $this->getRoleNames()->first();

        return $name ? Role::tryFrom($name) : null;
    }

    /**
     * Online means active in the Hub within the last five minutes.
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(5));
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /**
     * @return HasOne<PaymentDetail, $this>
     */
    public function paymentDetail(): HasOne
    {
        return $this->hasOne(PaymentDetail::class);
    }

    public function firstName(): string
    {
        return Str::before(trim($this->name), ' ');
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }

    /**
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * People in roles that sell and earn referral credit.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function sellers(Builder $query): void
    {
        $query->role(array_map(
            fn (Role $role): string => $role->value,
            array_filter(Role::cases(), fn (Role $role): bool => $role->earnsReferrals()),
        ));
    }
}
