<?php

namespace App\Services\Notifications;

/**
 * Turns a canonical webhook event + payload into one human-readable line for
 * chat channels (Slack / Telegram). Payloads come in two shapes: model events
 * carry ['id', 'type', 'attributes' => raw columns] (Services\Webhooks\Payload)
 * while product.low_stock passes a flat array — both are handled.
 */
class ChannelMessage
{
    protected const EMOJI = [
        'sale' => "\u{1F6D2}",                    // 🛒
        'sale_return' => "\u{21A9}\u{FE0F}",      // ↩️
        'purchase' => "\u{1F4E6}",                // 📦
        'purchase_return' => "\u{21A9}\u{FE0F}",
        'payment' => "\u{1F4B0}",                 // 💰
        'payment_purchase' => "\u{1F4B8}",        // 💸
        'payment_sale_return' => "\u{1F4B0}",
        'payment_purchase_return' => "\u{1F4B8}",
        'expense' => "\u{1F9FE}",                 // 🧾
        'cash_register' => "\u{1F3E6}",           // 🏦
        'client' => "\u{1F464}",                  // 👤
        'product' => "\u{1F3F7}\u{FE0F}",         // 🏷️
        'quotation' => "\u{1F4C4}",               // 📄
    ];

    public static function format(string $event, array $payload): string
    {
        [$entity, $action] = array_pad(explode('.', $event, 2), 2, '');
        $attrs = isset($payload['attributes']) && is_array($payload['attributes'])
            ? $payload['attributes']
            : $payload;

        if ($action === 'low_stock') {
            return sprintf(
                "\u{26A0}\u{FE0F} Low stock alert — %s (%s): %s left, alert threshold %s",
                $attrs['name'] ?? 'Product',
                $attrs['code'] ?? '—',
                $attrs['quantity'] ?? '?',
                $attrs['stock_alert'] ?? '?'
            );
        }

        $label = ucfirst(str_replace('_', ' ', $entity));
        $title = match ($action) {
            'created' => 'New ' . strtolower($label),
            'updated' => $label . ' updated',
            'deleted' => $label . ' deleted',
            'opened' => $label . ' opened',
            default => trim($label . ' ' . str_replace('_', ' ', $action)),
        };

        $parts = [];
        if (! empty($attrs['Ref'])) {
            $parts[] = 'Ref: ' . $attrs['Ref'];
        }
        if (isset($attrs['GrandTotal']) && $attrs['GrandTotal'] !== '') {
            $parts[] = 'Total: ' . $attrs['GrandTotal'];
        } elseif (isset($attrs['montant']) && $attrs['montant'] !== '') {
            $parts[] = 'Amount: ' . $attrs['montant'];
        }
        if (! empty($attrs['name'])) {
            $parts[] = 'Name: ' . $attrs['name'];
        } elseif (! empty($attrs['username'])) {
            $parts[] = 'Name: ' . $attrs['username'];
        }
        if (! empty($attrs['code'])) {
            $parts[] = 'Code: ' . $attrs['code'];
        }
        if (! empty($attrs['statut'])) {
            $parts[] = 'Status: ' . $attrs['statut'];
        }

        $emoji = self::EMOJI[$entity] ?? "\u{1F514}"; // 🔔

        return trim($emoji . ' ' . $title . ($parts ? ' — ' . implode(' · ', $parts) : ''));
    }
}
