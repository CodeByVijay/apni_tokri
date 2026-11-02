<?php

declare(strict_types=1);

/** @var string $bodyHtml Initial HTML for the editor */
/** @var string|null $fieldError */
$bodyHtml = $bodyHtml ?? '';
$fieldError = $fieldError ?? null;
$uploadUrl = url_base() . '/admin/cms/upload-image.php';
?>
<div class="cms-editor-wrap">
    <label class="at-label">Page content</label>
    <?php if ($fieldError): ?>
        <p class="text-sm text-red-600 mb-1"><?= Security::escape($fieldError) ?></p>
    <?php endif; ?>
    <div class="cms-editor-toolbar flex flex-wrap gap-1 mb-2 p-2 border rounded-lg bg-[var(--at-surface-2)]" role="toolbar" aria-label="Formatting">
        <button type="button" class="at-btn at-btn--secondary at-btn--sm cms-ed-cmd" data-cmd="bold" title="Bold"><strong>B</strong></button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm cms-ed-cmd" data-cmd="italic" title="Italic"><em>I</em></button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm cms-ed-block" data-tag="h2">H2</button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm cms-ed-block" data-tag="h3">H3</button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm cms-ed-block" data-tag="p">¶</button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm cms-ed-cmd" data-cmd="insertUnorderedList" title="Bullet list">• List</button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm cms-ed-cmd" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm cms-ed-block" data-tag="blockquote">Quote</button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm" id="cms-ed-link">Link</button>
        <button type="button" class="at-btn at-btn--secondary at-btn--sm" id="cms-ed-image" type="button">Image</button>
        <input type="file" id="cms-ed-image-file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden">
    </div>
    <div id="cms-ed-body" class="cms-editor-body at-textarea min-h-[14rem] p-3 border rounded-lg bg-white" contenteditable="true"><?= $bodyHtml ?></div>
    <textarea name="body_html" id="cms-ed-html" class="hidden" aria-hidden="true"></textarea>
    <p class="text-xs at-muted mt-1">Formatting only — scripts, iframes and unsafe HTML are removed on save.</p>
</div>
<script>
(function () {
    var body = document.getElementById('cms-ed-body');
    var hidden = document.getElementById('cms-ed-html');
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var uploadUrl = <?= json_encode($uploadUrl, JSON_UNESCAPED_SLASHES) ?>;

    function syncHidden() {
        hidden.value = body.innerHTML;
    }
    body.addEventListener('input', syncHidden);
    syncHidden();

    document.querySelectorAll('.cms-ed-cmd').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.execCommand(btn.getAttribute('data-cmd'), false, null);
            body.focus();
            syncHidden();
        });
    });
    document.querySelectorAll('.cms-ed-block').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.execCommand('formatBlock', false, btn.getAttribute('data-tag'));
            body.focus();
            syncHidden();
        });
    });
    document.getElementById('cms-ed-link').addEventListener('click', function () {
        var url = window.prompt('Link URL (https://…)');
        if (url) {
            document.execCommand('createLink', false, url);
            syncHidden();
        }
    });
    var fileInput = document.getElementById('cms-ed-image-file');
    document.getElementById('cms-ed-image').addEventListener('click', function () {
        fileInput.click();
    });
    fileInput.addEventListener('change', function () {
        if (!fileInput.files || !fileInput.files[0]) return;
        var fd = new FormData();
        fd.append('image', fileInput.files[0]);
        if (csrf) fd.append('_csrf_token', csrf.getAttribute('content'));
        fetch(uploadUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok && data.url) {
                    document.execCommand('insertImage', false, data.url);
                    syncHidden();
                } else {
                    window.alert((data && data.message) ? data.message : 'Upload failed.');
                }
            })
            .catch(function () { window.alert('Upload failed.'); });
        fileInput.value = '';
    });

    var form = body.closest('form');
    if (form) {
        form.addEventListener('submit', syncHidden);
    }
})();
</script>
