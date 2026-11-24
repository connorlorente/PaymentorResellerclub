<div class="container mt-14">
    <div class="flex flex-col gap-5">
        <h2 class="text-xl font-semibold">{{ __('Find your domain') }}</h2>

        <form wire:submit="search" class="flex gap-2">
            <input type="text" wire:model="query" placeholder="example or example.com" class="w-full rounded-lg border border-neutral bg-background-secondary p-3" required>
            <x-button.primary type="submit">{{ __('Search') }}</x-button.primary>
        </form>

        @if ($notice)
            <div class="rounded-lg border border-neutral bg-background-secondary p-4">{{ $notice }}</div>
        @endif

        @if ($results)
            <div class="flex flex-col gap-2">
                @foreach ($results as $row)
                    <div class="flex items-center justify-between rounded-lg border border-neutral bg-background-secondary p-4">
                        <span class="font-semibold">{{ $row['domain'] }}</span>
                        @if ($row['status'] === 'available')
                            <span class="flex items-center gap-4">
                                <span>{{ $row['price'] }}</span>
                                <a href="{{ $row['url'] }}" wire:navigate><x-button.primary>{{ __('Buy') }}</x-button.primary></a>
                            </span>
                        @elseif ($row['status'] === 'taken' && $row['url'])
                            <span class="flex items-center gap-4">
                                <span class="text-base/70">{{ __('Registered — already yours?') }}</span>
                                <a href="{{ $row['url'] }}" wire:navigate><x-button.secondary>{{ __('Transfer') }}</x-button.secondary></a>
                            </span>
                        @elseif ($row['status'] === 'taken')
                            <span class="text-base/70">{{ __('Taken') }}</span>
                        @else
                            <span class="text-base/70">{{ __('Could not check, try again') }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
