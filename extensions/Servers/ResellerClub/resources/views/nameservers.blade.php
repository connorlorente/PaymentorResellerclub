<div class="space-y-3" wire:poll.10s>
    <h3 class="font-semibold">Nameservers</h3>
    <textarea wire:model="nameservers" rows="4" class="w-full rounded border p-2" placeholder="ns1.example.com&#10;ns2.example.com"></textarea>
    <button wire:click="save" class="rounded px-4 py-2 border">Save nameservers</button>
    @if ($message)<p>{{ $message }}</p>@endif
    @if ($status)<p class="text-sm">Last change: {{ $status === 'completed' ? 'completed' : ($status === 'failed' ? 'failed' : 'pending') }}</p>@endif
</div>
