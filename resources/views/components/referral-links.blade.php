@props(['referralCode', 'editable' => true, 'size' => 'md'])

{{-- Needs a parent scope with x-data="{ target: 'stays' }". The Stays and Experiences links share one code. --}}
<div class="grid min-w-0 gap-3">
    <div class="inline-flex justify-self-start rounded-md border border-line bg-surface p-0.5" role="group" aria-label="Link for">
        @foreach (\App\Enums\ReferralTarget::cases() as $option)
            <button type="button" x-on:click="target = '{{ $option->value }}'" x-bind:aria-pressed="target === '{{ $option->value }}'"
                class="rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-brand"
                x-bind:class="target === '{{ $option->value }}' ? 'bg-brand-soft text-brand-text' : 'text-ink-subtle hover:text-ink'">{{ $option->label() }}</button>
        @endforeach
    </div>

    @foreach (\App\Enums\ReferralTarget::cases() as $option)
        @php
            $url = $referralCode->shareUrl($option);
            $message = $option->shareMessage($url);
        @endphp
        <div class="grid min-w-0 gap-3" x-show="target === '{{ $option->value }}'" @if (! $loop->first) x-cloak @endif>
            <div class="flex min-w-0 items-center gap-2 overflow-hidden rounded-lg border border-line bg-surface px-3 py-2.5">
                <x-ui.icon name="link" class="size-4 shrink-0 text-ink-subtle" />
                <span class="truncate text-sm text-ink-muted" title="{{ $url }}">{{ $url }}</span>
            </div>

            @if ($editable)
                <div class="flex flex-wrap gap-2" x-data="copyText(@js($url))">
                    <x-ui.button x-on:click="copy" icon="copy" :size="$size">
                        <span x-show="!copied">Copy {{ strtolower($option->label()) }} link</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </x-ui.button>
                    <x-ui.button variant="secondary" :size="$size" icon="chat" :href="'https://wa.me/?text='.rawurlencode($message)" target="_blank" rel="noopener">WhatsApp</x-ui.button>
                    <x-ui.button variant="secondary" :size="$size" icon="mail" :href="'mailto:?subject='.rawurlencode('List on Tourlast').'&body='.rawurlencode($message)">Email</x-ui.button>
                </div>
            @endif
        </div>
    @endforeach
</div>
