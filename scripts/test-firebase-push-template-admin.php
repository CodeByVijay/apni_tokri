<?php

declare(strict_types=1);

/**
 * Push template admin listing + CRUD (disposable DB).
 *
 * php scripts/run-in-disposable-db.php --migrate-firebase-push scripts/test-firebase-push-template-admin.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/disposable_db_runner.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$ok = 0;
$fail = 0;

function tplOk(bool $cond, string $label): void
{
    global $ok, $fail;
    if ($cond) {
        ++$ok;
        fwrite(STDOUT, "OK  {$label}\n");
    } else {
        ++$fail;
        fwrite(STDERR, "FAIL {$label}\n");
    }
}

$pdo = Database::pdo();
$seedCount = (int) $pdo->query('SELECT COUNT(*) FROM push_notification_templates')->fetchColumn();
tplOk($seedCount >= 10, 'default templates seeded (count ' . $seedCount . ')');

$list = PushAdminList::templates('', '', 1, 25);
tplOk($list['total'] === $seedCount, 'listing total matches DB');
tplOk(count($list['rows']) === min($seedCount, 25), 'listing returns rows (data-table rows fix)');

$listInactive = PushAdminList::templates('', 'inactive', 1, 100);
$inactiveDb = (int) $pdo->query('SELECT COUNT(*) FROM push_notification_templates WHERE is_active = 0')->fetchColumn();
tplOk($listInactive['total'] === $inactiveDb, 'active filter inactive');

$listQ = PushAdminList::templates('order.confirmed', '', 1, 10);
tplOk($listQ['total'] >= 1, 'search by template key');

$dup = PushTemplateAdmin::create([
    'template_key' => 'order.confirmed.push',
    'event_type' => 'order.status',
    'title_template' => 'X',
    'body_template' => 'Y',
    'is_active' => 1,
]);
tplOk(empty($dup['ok']), 'reject reserved system key on create');

$badPh = PushTemplateAdmin::create([
    'template_key' => 'custom.bad.placeholder',
    'event_type' => 'payment.success',
    'title_template' => 'Hi {bogus}',
    'body_template' => 'Ok {order_number}',
    'is_active' => 1,
]);
tplOk(empty($badPh['ok']), 'reject unsupported placeholder');

$create = PushTemplateAdmin::create([
    'template_key' => 'custom.test.push',
    'event_type' => 'announcement',
    'title_template' => '{title}',
    'body_template' => '{body}',
    'click_url_template' => '{click_url}',
    'is_active' => 1,
]);
tplOk(!empty($create['ok']), 'create custom template');
$customId = (int) ($create['id'] ?? 0);

$dupKey = PushTemplateAdmin::create([
    'template_key' => 'custom.test.push',
    'event_type' => 'announcement',
    'title_template' => 'A',
    'body_template' => 'B',
    'is_active' => 1,
]);
tplOk(empty($dupKey['ok']), 'reject duplicate template key');

$upd = PushTemplateAdmin::update($customId, [
    'title_template' => 'Updated title',
    'body_template' => 'Updated body',
    'is_active' => 0,
]);
tplOk(!empty($upd['ok']), 'edit custom template');

$row = PushTemplateAdmin::findById($customId);
tplOk(is_array($row) && (int) $row['is_active'] === 0, 'deactivate custom template');

$sysDel = PushTemplateAdmin::delete(
    (int) $pdo->query("SELECT id FROM push_notification_templates WHERE template_key = 'order.confirmed.push' LIMIT 1")->fetchColumn()
);
tplOk(empty($sysDel['ok']), 'system template delete blocked');

$del = PushTemplateAdmin::delete($customId);
tplOk(!empty($del['ok']), 'delete custom template');

$after = (int) $pdo->query('SELECT COUNT(*) FROM push_notification_templates WHERE template_key = \'custom.test.push\'')->fetchColumn();
tplOk($after === 0, 'custom row removed after delete');

$inactiveTpl = PushTemplateAdmin::update(
    (int) $pdo->query("SELECT id FROM push_notification_templates WHERE template_key = 'order.processing.push' LIMIT 1")->fetchColumn(),
    ['title_template' => 'T', 'body_template' => 'B', 'is_active' => 0]
);
tplOk(!empty($inactiveTpl['ok']), 'deactivate system template via update');

$render = PushTemplateRepository::findActive('order.processing.push');
tplOk($render === null, 'inactive templates not returned by findActive');

tplOk(PushTemplateRepository::findActive('order.confirmed.push') !== null, 'active system template still resolvable');

tplOk($list['total'] === 0 ? false : true, 'non-empty list when DB has templates');

fwrite(STDOUT, "\nResult: {$ok} passed, {$fail} failed\n");
exit($fail > 0 ? 1 : 0);
