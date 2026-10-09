<?php

namespace App\Actions\Travel\Providers;

use App\Models\ProviderContract;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\ContractTerms;
use Illuminate\Validation\ValidationException;

/**
 * Moves a contract through its workflow (see ContractTerms::Transitions),
 * checked on the server whatever the screen showed.
 */
class TransitionContract
{
    public function handle(ProviderContract $contract, string $action, User $actor, ?string $note = null): ProviderContract
    {
        abort_unless(ContractTerms::can($actor, $contract, $action), 403);

        if (in_array($action, ['return_draft', 'suspend', 'terminate'], true) && blank($note)) {
            throw ValidationException::withMessages(['transitionNote' => 'Give a reason.']);
        }

        [, $to, $label] = ContractTerms::Transitions[$action];
        $from = $contract->status;

        $contract->status = $to;

        if ($action === 'approve') {
            $contract->approved_by = $actor->id;
            $contract->approved_at = now();
        }

        $contract->save();

        Audit::record(
            $contract,
            'contract.'.$action,
            $label.': contract '.$contract->contract_number.' ('.$from->label().' → '.$to->label().')'.(filled($note) ? ' — '.$note : ''),
            ['status' => [$from->value, $to->value]],
        );

        return $contract;
    }
}
