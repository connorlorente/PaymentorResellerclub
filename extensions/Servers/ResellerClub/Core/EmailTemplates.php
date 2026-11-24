<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

/**
 * The one change this extension makes to Paymenter's stock emails, kept in code so a fresh install gets it too:
 * the "New order created" email lists the domain next to each item ("Solo example.co.uk").
 *
 * It only edits the stock line and never overwrites a template an admin has already changed in another way.
 */
class EmailTemplates
{
    public const ORDER_TEMPLATE_KEY = 'new_order_created';

    private const STOCK = '{{ $item->product->name }}';
    private const MARKER = "firstWhere('key', 'domain')";
    private const WITH_DOMAIN = "{{ \$item->product->name }} {{ \$item->properties->firstWhere('key', 'domain')?->value }}";

    /** Pure: the body with the domain added, or the same body when there is nothing to do. */
    public static function withDomain(string $body): string
    {
        if (str_contains($body, self::MARKER) || !str_contains($body, self::STOCK)) {
            return $body;
        }

        return preg_replace('/' . preg_quote(self::STOCK, '/') . '/', str_replace('\\', '\\\\', self::WITH_DOMAIN), $body, 1);
    }

    /** Applies it to the live template. Safe to call on every install and upgrade; never throws. */
    public static function apply(): void
    {
        try {
            $template = \App\Models\NotificationTemplate::where('key', self::ORDER_TEMPLATE_KEY)->first();
            if (!$template) {
                return;
            }
            $new = self::withDomain((string) $template->body);
            if ($new !== $template->body) {
                $template->body = $new;
                $template->save();
            }
        } catch (\Throwable $e) {
            SafeReport::report($e);
        }
    }
}
