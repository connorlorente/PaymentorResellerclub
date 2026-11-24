<div class="flex flex-col gap-4">
    <h3 class="text-lg font-semibold">{{ __('Transfer away') }}</h3>
    <p class="text-sm text-base/70">{{ __('To move this domain to another provider, turn the lock off and give them the transfer code below. Domains cannot usually be transferred within 60 days of registration.') }}</p>

    <div class="flex items-center justify-between rounded-lg border border-neutral bg-background-secondary p-4">
        <span>
            {{ __('Transfer lock') }}:
            <span class="font-semibold">
                @if ($locked === true) {{ __('On') }} @elseif ($locked === false) {{ __('Off') }} @else {{ __('Unknown') }} @endif
            </span>
        </span>
        <span class="flex gap-2">
            @if ($locked !== false)
                <x-button.secondary type="button" wire:click="setLock(false)" wire:confirm="{{ __('Turn the transfer lock off?') }}">{{ __('Unlock') }}</x-button.secondary>
            @endif
            @if ($locked !== true)
                <x-button.secondary type="button" wire:click="setLock(true)">{{ __('Lock') }}</x-button.secondary>
            @endif
        </span>
    </div>

    <div class="flex flex-col gap-2 rounded-lg border border-neutral bg-background-secondary p-4">
        <span class="font-semibold">{{ __('Transfer (auth) code') }}</span>
        @if ($code)
            <code class="select-all break-all rounded bg-background p-2">{{ $code }}</code>
            <div><x-button.secondary type="button" wire:click="hideCode">{{ __('Hide') }}</x-button.secondary></div>
        @else
            <div><x-button.primary type="button" wire:click="showCode">{{ __('Show transfer code') }}</x-button.primary></div>
        @endif
    </div>

    @if ($message)
        <p class="{{ $error ? 'text-red-500' : 'text-green-500' }}">{{ $message }}</p>
    @endif
</div>
