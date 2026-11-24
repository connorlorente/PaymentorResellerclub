<div class="flex flex-col gap-4">
    <h3 class="text-lg font-semibold">{{ __('WHOIS privacy') }}</h3>
    <p class="text-sm text-base/70">{{ __('Without privacy, the contact details on a domain can appear in the public WHOIS records. With privacy on, the registrar shows its own details instead.') }}</p>

    @if (!$supported)
        <div class="rounded-lg border border-neutral bg-background-secondary p-4">{{ __('WHOIS privacy is not offered for this type of domain.') }}</div>
    @else
        <div class="flex items-center justify-between rounded-lg border border-neutral bg-background-secondary p-4">
            <span>
                {{ __('WHOIS privacy') }}:
                <span class="font-semibold">
                    @if ($protected === true) {{ __('On') }} @elseif ($protected === false) {{ __('Off') }} @else {{ __('Unknown') }} @endif
                </span>
            </span>
            <span class="flex gap-2">
                @if ($protected !== true)
                    <x-button.primary type="button" wire:click="set(true)">{{ __('Turn on') }}</x-button.primary>
                @endif
                @if ($protected !== false)
                    <x-button.secondary type="button" wire:click="set(false)" wire:confirm="{{ __('Turn WHOIS privacy off? Your contact details may become public.') }}">{{ __('Turn off') }}</x-button.secondary>
                @endif
            </span>
        </div>
    @endif

    @if ($message)
        <p class="{{ $error ? 'text-red-500' : 'text-green-500' }}">{{ $message }}</p>
    @endif
</div>
