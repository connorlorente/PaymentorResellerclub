<?php

namespace Paymenter\Extensions\Others\InvoiceDomains;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Events\InvoiceItem\Creating;
use App\Models\Service;
use Illuminate\Support\Facades\Event;

#[ExtensionMeta(
    name: 'Invoice Domains',
    description: 'Adds the service domain to invoice line descriptions, e.g. "Solo - example.co.uk (Nov 26, 2026 - Dec 26, 2026)".',
    version: '1.0.0',
    author: 'Virtual Developments',
)]
class InvoiceDomains extends Extension
{
    public function getConfig($values = [])
    {
        return [];
    }

    public function boot()
    {
        Event::listen(Creating::class, function (Creating $event) {
            // Never let this break invoice creation (the nightly cron runs in one transaction).
            try {
                $item = $event->invoiceItem;

                if ($item->reference_type !== Service::class || !$item->reference_id) {
                    return;
                }

                $service = Service::find($item->reference_id);
                if (!$service) {
                    return;
                }

                $domain = $service->properties()->where('key', 'domain')->value('value');
                if (!$domain) {
                    return;
                }

                $description = (string) $item->description;
                if ($description === '' || str_contains($description, $domain)) {
                    return;
                }

                // "Solo (Nov 26 - Dec 26)" -> "Solo - example.co.uk (Nov 26 - Dec 26)"
                $productName = $service->product?->name;
                if ($productName && str_starts_with($description, $productName)) {
                    $item->description = $productName . ' - ' . $domain . substr($description, strlen($productName));
                } else {
                    $item->description = $description . ' - ' . $domain;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
