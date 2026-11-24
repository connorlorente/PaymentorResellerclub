<div class="flex flex-col gap-3" wire:poll.10s>
    <h3 class="text-lg font-semibold">{{ __('Nameservers') }}</h3>
    <p class="text-sm text-base/70">{{ __('Most domains should keep the default nameservers, which point at our hosting. Only change these if you host your DNS elsewhere.') }}</p>
    <textarea wire:model="nameservers" rows="4" class="w-full rounded-lg border border-neutral bg-background-secondary p-3" placeholder="ns1.example.com&#10;ns2.example.com"></textarea>
    <div class="flex gap-2">
        <x-button.primary type="button" wire:click="save">{{ __('Save nameservers') }}</x-button.primary>
        @if ($defaults)
            <x-button.secondary type="button" wire:click="useDefaults">{{ __('Use our default nameservers') }}</x-button.secondary>
        @endif
    </div>
    @if ($message)<p>{{ $message }}</p>@endif
    @if ($status)<p class="text-sm">{{ __('Last change') }}: {{ $status === 'completed' ? __('completed') : ($status === 'failed' ? __('failed') : __('pending')) }}</p>@endif
</div>
