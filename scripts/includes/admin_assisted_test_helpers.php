<?php

declare(strict_types=1);

/**
 * Helpers for admin-assisted order disposable-DB tests.
 *
 * @param array<string, mixed> $post
 * @return array{ok: bool, quote?: array<string, mixed>, place?: array<string, mixed>, message?: string}
 */
function admin_assisted_quote_and_place(array $post): array
{
    $input = AdminAssistedOrderService::collectPostInput($post);
    $quoted = AdminAssistedOrderService::quote($input, true);
    if (empty($quoted['ok'])) {
        return ['ok' => false, 'message' => (string) ($quoted['message'] ?? 'quote failed')];
    }
    $quote = $quoted['quote'] ?? [];
    $placeInput = AdminAssistedOrderService::collectPostInput($post + [
        'place_submission_token' => (string) ($quote['place_submission_token'] ?? ''),
        'client_grand_total' => (string) ($quote['grand_total'] ?? '0'),
    ]);
    $placed = AdminAssistedOrderService::placeOrder($placeInput);

    return [
        'ok' => !empty($placed['ok']),
        'quote' => is_array($quote) ? $quote : [],
        'place' => $placed,
        'message' => (string) ($placed['message'] ?? ''),
    ];
}
