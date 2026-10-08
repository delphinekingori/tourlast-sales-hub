<?php

namespace App\Livewire\Travel\Bookings\Concerns;

use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Runs an action and shows its validation errors against the component's
 * form fields (e.g. "capacity" → "form.capacity").
 */
trait CapturesActionErrors
{
    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T|null
     */
    protected function attempt(Closure $action, string $prefix = 'form', bool $flat = false): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [($flat || $prefix === '' ? $key : $prefix.'.'.$key) => $messages])
                ->all());

            return null;
        }
    }
}
