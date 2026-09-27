{{-- Livewire components call $this->dispatch('toast', message: '...', tone: 'success'). --}}
<div
    x-data="{
        toasts: [],
        push(detail) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message: detail.message, tone: detail.tone ?? 'success' });
            setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 4000);
        },
    }"
    x-init="@if (session('toast')) push(@js(session('toast'))) @endif"
    x-on:toast.window="push($event.detail)"
    class="pointer-events-none fixed inset-x-4 bottom-4 z-[60] flex flex-col items-end gap-2 sm:right-6 sm:left-auto"
    aria-live="polite"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-transition.opacity
            class="pointer-events-auto flex max-w-sm items-center gap-3 rounded-xl border border-line bg-surface px-4 py-3 text-sm text-ink shadow-overlay"
        >
            <span
                class="grid size-6 shrink-0 place-items-center rounded-full"
                :class="toast.tone === 'danger' ? 'bg-danger-soft text-danger' : 'bg-success-soft text-success'"
            >
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke="currentColor" aria-hidden="true">
                    <path x-show="toast.tone !== 'danger'" stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    <path x-show="toast.tone === 'danger'" stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008" />
                </svg>
            </span>
            <span x-text="toast.message"></span>
        </div>
    </template>
</div>
