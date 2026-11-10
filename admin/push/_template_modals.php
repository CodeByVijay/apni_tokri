<?php

declare(strict_types=1);

/** @var array<string, mixed> $formInput */
/** @var list<string> $formErrors */
/** @var array<string, mixed>|null $editRow */
/** @var bool $showCreate */
/** @var string $listSelf */

$formInput = $formInput ?? [];
$formErrors = $formErrors ?? [];
$editRow = $editRow ?? null;
$showCreate = $showCreate ?? false;

$openFormModal = ($showCreate && !is_array($editRow)) || is_array($editRow);
$isEdit = is_array($editRow);
$isCreate = $showCreate && !$isEdit;
$isSystem = $isEdit && PushTemplateCatalog::isSystemTemplateKey((string) $editRow['template_key']);

$closeGet = $_GET;
unset($closeGet['edit'], $closeGet['create']);
$cancelHref = $listSelf . PushAdminList::listRedirectQuery($closeGet);

$formTitle = $isCreate ? 'Create template' : 'Edit template';
$formAction = $isCreate ? 'create' : 'update';
?>

<div id="at-push-template-form-modal"
     class="at-modal-backdrop<?= $openFormModal ? ' is-open' : '' ?>"
     aria-hidden="<?= $openFormModal ? 'false' : 'true' ?>"
     role="presentation"
     data-at-push-template-form-modal>
    <div class="at-modal" role="dialog" aria-modal="true" aria-labelledby="at-push-template-form-title">
        <div class="at-modal__header">
            <h2 id="at-push-template-form-title" class="at-modal__title"><?= Security::escape($formTitle) ?></h2>
            <?php if ($openFormModal): ?>
                <a class="at-close at-focusable" href="<?= Security::escape($cancelHref) ?>" aria-label="Close"><span aria-hidden="true">×</span></a>
            <?php else: ?>
                <button type="button" class="at-close at-focusable" data-at-modal-close aria-label="Close"><span aria-hidden="true">×</span></button>
            <?php endif; ?>
        </div>
        <?php if ($openFormModal): ?>
        <form method="post" class="at-modal__body space-y-3" id="at-push-template-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="<?= Security::escape($formAction) ?>">
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <?php endif; ?>

            <?php if ($formErrors !== []): ?>
                <div class="at-alert at-alert--error">
                    <ul class="list-disc ml-5 m-0"><?php foreach ($formErrors as $err): ?>
                        <li><?= Security::escape($err) ?></li>
                    <?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <?php if ($isCreate): ?>
                <div>
                    <label class="at-label" for="tpl-key-create">Template key</label>
                    <input class="at-input at-focusable w-full" id="tpl-key-create" name="template_key" required maxlength="64"
                           pattern="[a-z0-9][a-z0-9._-]*" placeholder="e.g. marketing.summer.push"
                           value="<?= Security::escape(push_template_field(null, 'template_key', $formInput)) ?>">
                    <p class="at-muted text-xs mt-1">Lowercase letters, numbers, dots, underscores, hyphens. Must be unique.</p>
                </div>
                <div>
                    <label class="at-label" for="tpl-event-create">Event type</label>
                    <select class="at-select at-focusable w-full js-push-template-event" id="tpl-event-create" name="event_type" required>
                        <option value="">— Select event —</option>
                        <?php foreach (PushTemplateCatalog::allowedEventTypes() as $ev): ?>
                            <option value="<?= Security::escape($ev) ?>"<?= push_template_field(null, 'event_type', $formInput) === $ev ? ' selected' : '' ?>>
                                <?= Security::escape($ev) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <div class="text-sm space-y-1">
                    <p class="m-0">
                        <span class="at-muted">Template key</span><br>
                        <code><?= Security::escape((string) $editRow['template_key']) ?></code>
                    </p>
                    <p class="m-0">
                        <span class="at-muted">Event type</span><br>
                        <?= Security::escape((string) $editRow['event_type']) ?>
                    </p>
                    <?php if ($isSystem): ?>
                        <p class="m-0 flex flex-wrap items-center gap-2">
                            <span class="at-status at-status--info">System</span>
                            <span class="at-muted text-xs">System templates cannot be deleted. Deactivate instead of deleting.</span>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            $formRow = $isEdit ? $editRow : null;
            require __DIR__ . '/_template_form_fields.php';
            ?>

            <div class="at-modal__footer flex flex-wrap gap-2 pt-2 border-t border-[var(--at-border)] -mx-1 px-1">
                <button type="submit" class="at-btn at-btn--primary at-focusable">Save template</button>
                <a class="at-btn at-btn--ghost at-focusable" href="<?= Security::escape($cancelHref) ?>">Cancel</a>
            </div>
        </form>
        <?php else: ?>
        <div class="at-modal__body">
            <p class="text-sm at-muted m-0">Open a template from the list to edit, or use Create Template.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div id="at-push-template-delete-modal"
     class="at-modal-backdrop"
     aria-hidden="true"
     role="presentation"
     data-at-push-template-delete-modal>
    <div class="at-modal" role="dialog" aria-modal="true" aria-labelledby="at-push-template-delete-title">
        <form method="post" id="at-push-template-delete-form">
            <div class="at-modal__header">
                <h2 id="at-push-template-delete-title" class="at-modal__title">Delete template?</h2>
                <button type="button" class="at-close at-focusable" data-at-push-template-delete-close aria-label="Close"><span aria-hidden="true">×</span></button>
            </div>
            <div class="at-modal__body space-y-2 text-sm">
                <p class="m-0 at-muted">This action cannot be undone. Historical notification logs are kept.</p>
                <dl class="m-0 space-y-1">
                    <dt class="at-muted text-xs">Template key</dt>
                    <dd class="m-0 font-mono text-sm" id="at-push-template-delete-key"></dd>
                    <dt class="at-muted text-xs mt-2">Notification title</dt>
                    <dd class="m-0" id="at-push-template-delete-title-text"></dd>
                </dl>
            </div>
            <div class="at-modal__footer flex flex-wrap gap-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="form_action" value="delete">
                <input type="hidden" name="id" value="" id="at-push-template-delete-id">
                <button type="button" class="at-btn at-btn--secondary at-focusable" data-at-push-template-delete-close>Cancel</button>
                <button type="submit" class="at-btn at-btn--danger at-focusable" id="at-push-template-delete-submit">Delete template</button>
            </div>
        </form>
    </div>
</div>

<script type="application/json" id="at-push-template-placeholders-json"><?=
    json_encode(PushTemplateCatalog::placeholdersByEventType(), JSON_THROW_ON_ERROR | JSON_HEX_TAG)
?></script>
