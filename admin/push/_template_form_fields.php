<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $formRow from edit row or null on create */
/** @var array<string, mixed> $formInput POST overrides */

$formRow = $formRow ?? null;
$formInput = $formInput ?? [];
$eventType = push_template_field($formRow, 'event_type', $formInput);
$placeholders = PushTemplateCatalog::placeholdersForEventType($eventType);
$phHint = $placeholders === []
    ? 'Select an event type to see allowed placeholders.'
    : implode(', ', array_map(static fn (string $p): string => '{' . $p . '}', $placeholders));
$activeChecked = push_template_is_active_checked($formRow, $formInput);
?>
<p class="text-xs at-muted js-push-template-placeholders" data-event="<?= Security::escape($eventType) ?>">Allowed placeholders: <?= Security::escape($phHint) ?></p>
<div>
    <label class="at-label" for="title-template">Notification title</label>
    <input class="at-input at-focusable w-full" id="title-template" name="title_template" required maxlength="255"
           placeholder="e.g. Order confirmed"
           value="<?= Security::escape(push_template_field($formRow, 'title_template', $formInput)) ?>">
</div>
<div>
    <label class="at-label" for="body-template">Notification body</label>
    <textarea class="at-input at-focusable w-full" id="body-template" name="body_template" rows="3" required maxlength="512"
              placeholder="e.g. Hi {customer_name}, order {order_number} is confirmed."><?= Security::escape(push_template_field($formRow, 'body_template', $formInput)) ?></textarea>
</div>
<div>
    <label class="at-label" for="click-url-template">Click URL (optional)</label>
    <input class="at-input at-focusable w-full" id="click-url-template" name="click_url_template" maxlength="512"
           placeholder="e.g. /order-details.php?id={order_id}"
           value="<?= Security::escape(push_template_field($formRow, 'click_url_template', $formInput)) ?>">
</div>
<label class="flex items-center gap-2 text-sm">
    <input type="checkbox" name="is_active" value="1"<?= $activeChecked ? ' checked' : '' ?>> Active
</label>
