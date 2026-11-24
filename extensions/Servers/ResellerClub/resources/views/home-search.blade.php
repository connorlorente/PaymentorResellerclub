{{-- Inline styles on purpose: the theme's compiled stylesheet only contains the utility classes the theme itself uses. --}}
<div class="container mb-10">
    <div class="bg-background-secondary border border-neutral rounded-lg" style="padding:1.5rem;">
        <h2 class="text-xl font-semibold" style="margin-bottom:1rem;">{{ __('Find your domain') }}</h2>

        <form action="{{ route('resellerclub.domains') }}" method="GET" style="display:flex;gap:.75rem;align-items:stretch;flex-wrap:wrap;">
            <input type="text" name="q" placeholder="example or example.co.uk" required
                   class="bg-background border border-neutral rounded-lg"
                   style="flex:1 1 16rem;min-width:0;padding:.75rem 1rem;">
            <div style="flex:0 0 9rem;"><x-button.primary type="submit" style="height:100%;">{{ __('Search') }}</x-button.primary></div>
        </form>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(7.5rem,1fr));gap:.75rem;margin-top:1.25rem;">
            @foreach ($tlds as $tld => $price)
                <div class="bg-background border border-neutral rounded-lg" style="padding:.6rem .9rem;text-align:center;">
                    <div class="font-semibold">.{{ $tld }}</div>
                    <div style="font-size:.85rem;opacity:.75;">{{ __('from') }} {{ $price }}{{ __('/yr') }}</div>
                </div>
            @endforeach
        </div>
    </div>
</div>
