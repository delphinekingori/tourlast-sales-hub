<?php

namespace App\Actions\Travel\Providers;

use App\Models\ContractDocument;
use App\Models\ProviderContract;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\ContractTerms;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Stores a contract file on the private disk under travel/contracts/{id}.
 * Replacing a file keeps the earlier version, linked to the new one.
 */
class StoreContractDocument
{
    public function handle(ProviderContract $contract, UploadedFile $file, string $type, User $actor, ?ContractDocument $replacing = null): ContractDocument
    {
        $provider = $contract->provider;
        abort_unless(ContractTerms::canAttach($actor, $provider), 403);
        abort_unless(array_key_exists($type, ContractDocument::Types), 422);
        abort_if($replacing && ($replacing->provider_contract_id !== $contract->id || ! $replacing->isCurrent()), 422);

        $path = $file->store('travel/contracts/'.$contract->id, 'local');

        return DB::transaction(function () use ($contract, $file, $type, $actor, $replacing, $path): ContractDocument {
            $document = $contract->documents()->create([
                'type' => $type,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'size' => (int) $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_by' => $actor->id,
            ]);

            if ($replacing) {
                $replacing->update(['replaced_by_id' => $document->id]);

                Audit::record($contract, 'contract.document_replaced', 'Replaced '.$replacing->typeLabel().' "'.$replacing->original_name.'" with "'.$document->original_name.'" on contract '.$contract->contract_number, [
                    'document' => [$replacing->original_name, $document->original_name],
                ]);
            } else {
                Audit::record($contract, 'contract.document_added', 'Added '.$document->typeLabel().' "'.$document->original_name.'" to contract '.$contract->contract_number);
            }

            return $document;
        });
    }
}
