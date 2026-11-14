<?php

declare(strict_types=1);

/** @var string $bodyHtml */
/** @var list<string> $variableNames */
$bodyHtml = $bodyHtml ?? '';
$variableNames = $variableNames ?? [];
?>
<div class="email-tpl-editor">
    <label class="at-label" for="email-tpl-subject">Email subject</label>
    <input type="text" id="email-tpl-subject" name="subject" class="at-input at-focusable w-full mb-2" maxlength="255" required
           value="<?= Security::escape($subject ?? '') ?>">

    <div class="flex flex-wrap gap-1 mb-2" aria-label="Insert into subject">
        <?php foreach ($variableNames as $var): ?>
            <button type="button" class="at-btn at-btn--secondary at-btn--sm email-tpl-var" data-target="email-tpl-subject" data-var="<?= Security::escape($var) ?>">{{<?= Security::escape($var) ?>}}</button>
        <?php endforeach; ?>
    </div>

    <label class="at-label">Email body (content only)</label>
    <p class="text-xs at-muted mb-2">Site header, footer and brand colours are added automatically when the email is sent. Edit the inner content below.</p>
    <div class="cms-editor-toolbar flex flex-wrap gap-1 mb-2 p-2 border rounded-lg bg-[var(--at-surface-2)]" role="toolbar" aria-label="Formatting">
        <button type="button" class="at-btn at-btn--secondary at-btn--sm email-ed-cmd" data-cmd="bold"><strong>B</strong></button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm email-ed-cmd" data-cmd="italic"><em>I</em></button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm email-ed-block" data-tag="h2">H2</button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm email-ed-block" data-tag="p">¶</button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm email-ed-cmd" data-cmd="insertUnorderedList">• List</button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm" id="email-ed-link" type="button">Link</button>
    </div>
    <div class="flex flex-wrap gap-1 mb-2" aria-label="Insert into body">
        <?php foreach ($variableNames as $var): ?>
            <button type="button" class="at-btn at-btn--secondary at-btn--sm email-tpl-var" data-target="email-ed-body" data-var="<?= Security::escape($var) ?>">{{<?= Security::escape($var) ?>}}</button>
        <?php endforeach; ?>
    </div>
    <div id="email-ed-body" class="cms-editor-body at-textarea min-h-[14rem] p-3 border rounded-lg bg-white" contenteditable="true"><?= $bodyHtml ?></div>
    <textarea name="body_html" id="email-ed-html" class="hidden" aria-hidden="true"></textarea>
</div>
<script <?= Security::cspNonceAttr() ?>>
(function () {
    var body = document.getElementById('email-ed-body');
    var hidden = document.getElementById('email-ed-html');
    function syncHidden() { hidden.value = body.innerHTML; }
    body.addEventListener('input', syncHidden);
    syncHidden();

    document.querySelectorAll('.email-ed-cmd').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.execCommand(btn.getAttribute('data-cmd'), false, null);
            body.focus();
            syncHidden();
        });
    });
    document.querySelectorAll('.email-ed-block').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.execCommand('formatBlock', false, btn.getAttribute('data-tag'));
            body.focus();
            syncHidden();
        });
    });
    document.getElementById('email-ed-link').addEventListener('click', function () {
        var url = window.prompt('Link URL (https://…)');
        if (url) {
            document.execCommand('createLink', false, url);
            syncHidden();
        }
    });

    document.querySelectorAll('.email-tpl-var').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target');
            var token = '{{' + btn.getAttribute('data-var') + '}}';
            if (targetId === 'email-ed-body') {
                document.execCommand('insertText', false, token);
                syncHidden();
                body.focus();
                return;
            }
            var el = document.getElementById(targetId);
            if (!el) return;
            var start = el.selectionStart || 0;
            var end = el.selectionEnd || 0;
            var val = el.value;
            el.value = val.slice(0, start) + token + val.slice(end);
            el.focus();
            el.selectionStart = el.selectionEnd = start + token.length;
        });
    });

    var form = body.closest('form');
    if (form) {
        form.addEventListener('submit', syncHidden);
    }
    window.emailTemplateEditorSync = syncHidden;
})();
</script>
