<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

/**
 * A file on a provider contract (signed contract, addendum, rate sheet,
 * terms, other). Stored on the private "local" disk and never deleted:
 * a replaced file points at its newer version, a removed one is marked.
 */
#[Fillable(['provider_contract_id', 'type', 'path', 'original_name', 'size', 'mime_type', 'uploaded_by', 'replaced_by_id', 'removed_at', 'removed_by'])]
class ContractDocument extends Model
{
    public const Types = [
        'signed_contract' => 'Signed contract',
        'addendum' => 'Addendum',
        'rate_sheet' => 'Rate sheet',
        'terms' => 'Terms',
        'insurance' => 'Insurance',
        'permit' => 'Permit / licence',
        'other' => 'Other',
    ];

    /** Accepted file types: PDF and Word. */
    public const Extensions = ['pdf', 'doc', 'docx'];

    /** Largest upload in KB (15 MB, within the server's upload limit). */
    public const MaxKilobytes = 15360;

    /**
     * Validation for an uploaded contract file: extension and detected
     * content type must both be PDF or Word.
     *
     * @return list<string>
     */
    public static function fileRules(): array
    {
        $types = implode(',', self::Extensions);

        return ['file', 'max:'.self::MaxKilobytes, 'extensions:'.$types, 'mimes:'.$types];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'removed_at' => 'datetime',
        ];
    }

    /**
     * Files still in use: not replaced by a newer version and not removed.
     *
     * @param  Builder<ContractDocument>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('replaced_by_id')->whereNull('removed_at');
    }

    /**
     * @return BelongsTo<ProviderContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProviderContract::class, 'provider_contract_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    /**
     * @return BelongsTo<ContractDocument, $this>
     */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function isCurrent(): bool
    {
        return $this->replaced_by_id === null && $this->removed_at === null;
    }

    public function typeLabel(): string
    {
        return self::Types[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    public function sizeLabel(): string
    {
        return Number::fileSize((int) $this->size, precision: 1);
    }
}
