<?php

namespace App\Actions\Travel\Providers;

use App\Models\ContractDocument;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\ContractTerms;

/**
 * Takes a file off a contract. The file and its row are kept (marked as
 * removed) so the contract's history stays complete.
 */
class RemoveContractDocument
{
    public function handle(ContractDocument $document, User $actor): void
    {
        $contract = $document->contract;
        abort_unless(ContractTerms::canAttach($actor, $contract->provider), 403);
        abort_unless($document->isCurrent(), 422);

        $document->update(['removed_at' => now(), 'removed_by' => $actor->id]);

        Audit::record($contract, 'contract.document_removed', 'Removed '.$document->typeLabel().' "'.$document->original_name.'" from contract '.$contract->contract_number);
    }
}
