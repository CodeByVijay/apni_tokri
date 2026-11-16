<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('notifications');

$jobId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$backQs = http_build_query(array_filter([
    'status' => isset($_GET['status']) && trim((string) $_GET['status']) !== '' ? trim((string) $_GET['status']) : null,
    'channel' => isset($_GET['channel']) && trim((string) $_GET['channel']) !== '' ? trim((string) $_GET['channel']) : null,
    'event' => isset($_GET['event']) && trim((string) $_GET['event']) !== '' ? trim((string) $_GET['event']) : null,
    'page' => isset($_GET['page']) && (int) $_GET['page'] > 1 ? (string) (int) $_GET['page'] : null,
]));
$backHref = url_base() . '/admin/notifications/delivery-logs.php' . ($backQs !== '' ? '?' . $backQs : '');

admin_layout_start(['pageTitle' => 'Notification detail', 'activeAdminNav' => 'notifications']);

$pageHeading = 'Notification detail';
$pageActionsHtml = '<a href="' . Security::escape($backHref) . '" class="at-btn at-btn--secondary at-btn--sm at-focusable">Back to delivery logs</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if (!Install::isSchemaReady() || !NotificationQueueService::tableReady()) {
    echo '<div class="at-alert at-alert--error">Notification queue is not available.</div>';
    admin_layout_end();
    exit;
}

if ($jobId <= 0) {
    echo '<div class="at-alert at-alert--warn">Invalid notification ID.</div>';
    admin_layout_end();
    exit;
}

$model = NotificationQueueAdminDetailService::viewModel($jobId);
if ($model === null) {
    http_response_code(404);
    echo '<div class="at-alert at-alert--warn">Notification #' . (int) $jobId . ' was not found.</div>';
    admin_layout_end();
    exit;
}

$overview = $model['overview'];
$preview = $model['preview'] ?? null;
$email = $model['email'] ?? null;
$sms = $model['sms'] ?? null;
$inApp = $model['in_app'] ?? null;
$relations = $model['relations'] ?? [];
$emailLogs = $model['delivery_email_logs'] ?? [];
$limitations = $model['limitations'] ?? [];
$attemptNotes = $model['attempt_notes'] ?? [];
?>
<div class="space-y-4">
    <section class="at-card">
        <div class="at-card__body">
            <h2 class="text-lg font-bold mb-3">Overview</h2>
            <dl class="at-dl at-dl--compact grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="at-dl__dt">Notification ID</dt><dd class="font-mono"><?= (int) ($overview['id'] ?? 0) ?></dd></div>
                <div><dt class="at-dl__dt">Event</dt><dd><?= Security::escape((string) ($overview['event_type'] ?? '')) ?></dd></div>
                <div><dt class="at-dl__dt">Channel</dt><dd><?= Security::escape((string) ($overview['channel_label'] ?? '')) ?></dd></div>
                <div><dt class="at-dl__dt">Recipient</dt><dd><?= Security::escape((string) ($overview['recipient'] ?? '')) ?></dd></div>
                <div><dt class="at-dl__dt">Status</dt><dd><?= admin_status_badge((string) ($overview['status'] ?? '')) ?></dd></div>
                <div><dt class="at-dl__dt">Attempts</dt><dd><?= (int) ($overview['attempts'] ?? 0) ?> / <?= (int) ($overview['max_attempts'] ?? 0) ?></dd></div>
                <div><dt class="at-dl__dt">Created</dt><dd><?= Security::escape(customer_format_datetime((string) ($overview['created_at'] ?? ''))) ?></dd></div>
                <div><dt class="at-dl__dt">Last attempted</dt><dd><?= ($overview['last_attempt_at'] ?? '') !== '' ? Security::escape(customer_format_datetime((string) $overview['last_attempt_at'])) : '—' ?></dd></div>
                <div><dt class="at-dl__dt">Sent</dt><dd><?= ($overview['sent_at'] ?? '') !== '' ? Security::escape(customer_format_datetime((string) $overview['sent_at'])) : '—' ?></dd></div>
                <div><dt class="at-dl__dt">Cancelled</dt><dd><?= ($overview['cancelled_at'] ?? '') !== '' ? Security::escape(customer_format_datetime((string) $overview['cancelled_at'])) : '—' ?></dd></div>
                <div class="sm:col-span-2"><dt class="at-dl__dt">Template</dt><dd class="font-mono text-sm"><?= Security::escape((string) ($overview['template_key'] ?? '')) ?></dd></div>
                <?php if (($overview['error_message'] ?? '') !== ''): ?>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <dt class="at-dl__dt">Last error</dt>
                        <dd class="text-red-700"><?= Security::escape((string) $overview['error_message']) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>
    </section>

    <?php if ($preview !== null && !empty($preview['notice'])): ?>
        <div class="at-alert at-alert--info">
            <p class="text-sm"><?= Security::escape((string) $preview['notice']) ?></p>
        </div>
    <?php endif; ?>

    <?php if (is_array($email)): ?>
        <section class="at-card">
            <div class="at-card__body space-y-3">
                <h2 class="text-lg font-bold">Email content</h2>
                <dl class="at-dl at-dl--compact grid gap-3 sm:grid-cols-2">
                    <div><dt class="at-dl__dt">From</dt><dd><?= ($email['from'] ?? null) !== null ? Security::escape((string) $email['from']) : '—' ?></dd></div>
                    <div><dt class="at-dl__dt">To</dt><dd><?= Security::escape((string) ($email['to'] ?? '')) ?></dd></div>
                    <div class="sm:col-span-2"><dt class="at-dl__dt">Subject</dt><dd><?= ($email['subject'] ?? '') !== '' ? Security::escape((string) $email['subject']) : '—' ?> <span class="text-xs at-muted">(re-rendered)</span></dd></div>
                </dl>
                <?php if (($email['text'] ?? '') !== ''): ?>
                    <div>
                        <h3 class="font-semibold text-sm mb-1">Plain text (re-rendered)</h3>
                        <pre class="text-xs whitespace-pre-wrap border rounded p-3 bg-slate-50 max-h-48 overflow-auto"><?= Security::escape((string) $email['text']) ?></pre>
                    </div>
                <?php endif; ?>
                <?php if (($email['html'] ?? '') !== ''): ?>
                    <div>
                        <h3 class="font-semibold text-sm mb-1">HTML preview (re-rendered, sandboxed)</h3>
                        <iframe title="Email HTML preview" class="w-full border rounded-lg bg-white min-h-[14rem]"
                                sandbox="" srcdoc="<?= Security::escape((string) $email['html']) ?>"></iframe>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (is_array($sms)): ?>
        <section class="at-card">
            <div class="at-card__body space-y-2">
                <h2 class="text-lg font-bold">SMS content</h2>
                <dl class="at-dl at-dl--compact grid gap-3 sm:grid-cols-2">
                    <div><dt class="at-dl__dt">Mobile</dt><dd><?= Security::escape((string) ($sms['mobile_masked'] ?? '')) ?></dd></div>
                    <div><dt class="at-dl__dt">Provider</dt><dd><?= Security::escape((string) ($sms['provider'] ?? '')) ?></dd></div>
                    <div class="sm:col-span-2"><dt class="at-dl__dt">Message</dt><dd class="whitespace-pre-wrap"><?= ($sms['body'] ?? '') !== '' ? Security::escape((string) $sms['body']) : '—' ?> <span class="text-xs at-muted">(re-rendered)</span></dd></div>
                    <?php if (($sms['provider_response'] ?? '') !== ''): ?>
                        <div class="sm:col-span-2"><dt class="at-dl__dt">Last queue error</dt><dd><?= Security::escape((string) $sms['provider_response']) ?></dd></div>
                    <?php endif; ?>
                </dl>
            </div>
        </section>
    <?php endif; ?>

    <?php if (is_array($inApp)): ?>
        <section class="at-card">
            <div class="at-card__body space-y-2">
                <h2 class="text-lg font-bold">In-app notification</h2>
                <dl class="at-dl at-dl--compact grid gap-3 sm:grid-cols-2">
                    <div><dt class="at-dl__dt">Recipient ref</dt><dd class="font-mono text-sm"><?= Security::escape((string) ($inApp['recipient_ref'] ?? '')) ?></dd></div>
                    <div class="sm:col-span-2"><dt class="at-dl__dt">Title</dt><dd><?= Security::escape((string) ($inApp['title'] ?? '')) ?></dd></div>
                    <div class="sm:col-span-2"><dt class="at-dl__dt">Body</dt><dd class="whitespace-pre-wrap"><?= Security::escape((string) ($inApp['body'] ?? '')) ?></dd></div>
                </dl>
            </div>
        </section>
    <?php endif; ?>

    <section class="at-card">
        <div class="at-card__body">
            <h2 class="text-lg font-bold mb-2">Stored job variables</h2>
            <p class="text-xs at-muted mb-2">Sanitized copy of payload_json (secrets redacted).</p>
            <pre class="text-xs whitespace-pre-wrap border rounded p-3 bg-slate-50 max-h-64 overflow-auto"><?= Security::escape((string) ($model['payload_json_pretty'] ?? '{}')) ?></pre>
        </div>
    </section>

    <?php if ($relations !== []): ?>
        <section class="at-card">
            <div class="at-card__body">
                <h2 class="text-lg font-bold mb-2">Related records</h2>
                <ul class="list-disc pl-5 space-y-1">
                    <?php foreach ($relations as $rel): ?>
                        <li><a class="underline font-semibold at-focusable" href="<?= Security::escape((string) ($rel['href'] ?? '#')) ?>"><?= Security::escape((string) ($rel['label'] ?? '')) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endif; ?>

    <section class="at-card">
        <div class="at-card__body">
            <h2 class="text-lg font-bold mb-2">Delivery history</h2>
            <?php foreach ($attemptNotes as $note): ?>
                <p class="text-sm at-muted mb-2"><?= Security::escape($note) ?></p>
            <?php endforeach; ?>
            <?php if ($emailLogs !== []): ?>
                <div class="at-admin-table-wrap mt-3">
                    <table class="at-admin-table at-admin-table--dense">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th class="is-num">Attempt</th>
                                <th>Status</th>
                                <th>Started</th>
                                <th>Completed</th>
                                <th>Error</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($emailLogs as $log): ?>
                            <tr>
                                <td>Order email log</td>
                                <td class="is-num"><?= (int) ($log['attempt_number'] ?? 0) ?></td>
                                <td><?= admin_status_badge((string) ($log['status'] ?? '')) ?></td>
                                <td class="is-nowrap"><?= Security::escape(customer_format_datetime((string) ($log['started_at'] ?? ''))) ?></td>
                                <td class="is-nowrap"><?= ($log['completed_at'] ?? '') !== '' ? Security::escape(customer_format_datetime((string) $log['completed_at'])) : '—' ?></td>
                                <td><?= ($log['error'] ?? '') !== '' ? Security::escape((string) $log['error']) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-sm at-muted mt-2">No linked attempt rows available for this notification.</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="at-card">
        <div class="at-card__body">
            <h2 class="text-lg font-bold mb-2">Data notes</h2>
            <ul class="list-disc pl-5 text-sm at-muted space-y-1">
                <?php foreach ($limitations as $lim): ?>
                    <li><?= Security::escape($lim) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
</div>
<?php
admin_layout_end();
