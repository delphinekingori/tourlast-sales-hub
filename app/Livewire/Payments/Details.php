<?php

namespace App\Livewire\Payments;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Everyone's payout details. Admins, HR and Finance only; Sales Managers can't open it.
 */
#[Title('Payment details')]
class Details extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $method = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ViewPaymentDetails->value), 403);
    }

    public function render(): View
    {
        $people = User::query()
            ->sellers()
            ->with(['paymentDetail', 'roles'])
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->method === 'missing', fn ($query) => $query->whereDoesntHave('paymentDetail'))
            ->when(in_array($this->method, ['mpesa', 'bank'], true), fn ($query) => $query->whereHas('paymentDetail', fn ($query) => $query->where('method', $this->method)))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return view('livewire.payments.details', [
            'people' => $people,
            'missing' => $people->filter(fn (User $user): bool => ! $user->paymentDetail?->isComplete())->count(),
        ]);
    }
}
