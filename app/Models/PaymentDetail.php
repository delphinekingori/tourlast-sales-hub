<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a salesperson's incentives are paid: M-Pesa or a bank account.
 * Phone numbers, account numbers and names are encrypted at rest.
 */
#[Fillable(['user_id', 'method', 'mpesa_phone', 'mpesa_name', 'bank_name', 'bank_branch', 'account_number', 'account_name'])]
#[Hidden(['mpesa_phone', 'account_number'])]
class PaymentDetail extends Model
{
    public const Methods = ['mpesa' => 'M-Pesa', 'bank' => 'Bank account'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mpesa_phone' => 'encrypted',
            'mpesa_name' => 'encrypted',
            'account_number' => 'encrypted',
            'account_name' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isComplete(): bool
    {
        return $this->method === 'mpesa'
            ? filled($this->mpesa_phone) && filled($this->mpesa_name)
            : filled($this->account_number) && filled($this->account_name);
    }

    /**
     * Name the money is sent to, as it shows on M-Pesa or the bank record.
     */
    public function payeeName(): ?string
    {
        return $this->method === 'mpesa' ? $this->mpesa_name : $this->account_name;
    }

    public function destination(): ?string
    {
        return $this->method === 'mpesa'
            ? $this->mpesa_phone
            : trim(($this->bank_name ? $this->bank_name.' · ' : '').$this->account_number);
    }

    /**
     * For the salesperson's own screen: enough to recognise, not enough to copy.
     */
    public function maskedDestination(): string
    {
        $value = (string) ($this->method === 'mpesa' ? $this->mpesa_phone : $this->account_number);

        return strlen($value) > 6 ? substr($value, 0, 4).' ••• '.substr($value, -3) : $value;
    }

    /**
     * 07xx / 01xx / +2547xx / 2547xx → 2547xxxxxxxx.
     */
    public static function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return match (true) {
            str_starts_with($digits, '254') => $digits,
            str_starts_with($digits, '0') => '254'.substr($digits, 1),
            default => '254'.$digits,
        };
    }
}
