<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        @if (!$server)
            <x-filament::section>
                <x-slot name="heading">Not connected yet</x-slot>
                Add a server under <strong>Extensions &gt; Servers</strong> using the <strong>ResellerClub Domains</strong>
                extension (reseller ID and API key), then come back here to create the domain products.
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Domain products</x-slot>
            <x-slot name="description">One product per extension. New products start <strong>hidden</strong> (not offered at checkout or on /domains): check the prices, then untick "Hide product" on the product to put it on sale.</x-slot>

            @if (count($rows) === 0)
                <p class="text-sm text-gray-500">No domain products yet. Use <strong>Create TLD products</strong> above.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pr-4">Extension</th>
                                <th class="py-2 pr-4">Product</th>
                                <th class="py-2 pr-4">Category</th>
                                <th class="py-2 pr-4">Prices ({{ $currency }})</th>
                                <th class="py-2 pr-4">Visible</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-t border-gray-200 dark:border-white/10">
                                    <td class="py-2 pr-4 font-medium">.{{ $row['tld'] }}</td>
                                    <td class="py-2 pr-4">
                                        <a class="text-primary-600 hover:underline" href="{{ url('admin/products/' . $row['id'] . '/edit') }}">{{ $row['name'] }}</a>
                                    </td>
                                    <td class="py-2 pr-4">{{ $row['category'] }}</td>
                                    <td class="py-2 pr-4">{{ $row['prices'] }}</td>
                                    <td class="py-2 pr-4">{{ $row['hidden'] ? 'Hidden' : 'Yes' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        @if (count($proposals) > 0)
            <x-filament::section>
                <x-slot name="heading">Proposed price changes ({{ count($proposals) }})</x-slot>
                <x-slot name="description">From the latest price sync: registrar cost plus your markup. Review, then use <strong>Apply proposed prices</strong>.</x-slot>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pr-4">Product</th>
                                <th class="py-2 pr-4">Term</th>
                                <th class="py-2 pr-4">Current</th>
                                <th class="py-2 pr-4">Proposed</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($proposals as $p)
                                <tr class="border-t border-gray-200 dark:border-white/10">
                                    <td class="py-2 pr-4">{{ $p['product'] ?? ('.' . ($p['tld'] ?? '')) }}</td>
                                    <td class="py-2 pr-4">{{ $p['years'] ?? '?' }} yr</td>
                                    <td class="py-2 pr-4">{{ isset($p['current']) ? number_format((float) $p['current'], 2) : '—' }}</td>
                                    <td class="py-2 pr-4 font-medium">{{ number_format((float) ($p['proposed'] ?? 0), 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">How domains work with hosting</x-slot>
            <ul class="list-disc pl-5 text-sm space-y-1">
                <li>Use the <strong>Plesk + Domains</strong> server module on a hosting product to add "Register / Transfer / Use my own domain" to its checkout.</li>
                <li>Register or Transfer adds the matching domain product above to the cart, at its 1-year price.</li>
                <li>Registrant details come from the customer's account (address, phone, country). If they're incomplete, the registration shows as a failed operation under <strong>Domain operations</strong>, where you can retry once the account is complete.</li>
                <li>Suspending or cancelling a domain service never deletes or releases the domain at ResellerClub.</li>
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
