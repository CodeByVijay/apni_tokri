<?php

declare(strict_types=1);

/** Admin delete / archive safety regression tests. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
Install::ensureConnection();
$pdo = Database::pdo();
$pass = 0;
$fail = 0;
function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'PASS' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$_SESSION['admin_user_id'] = 1;
Permission::clearCache();
$tok = strtolower(bin2hex(random_bytes(3)));

// CMS protected slug
$about = CmsPageRepository::findBySlug('about-us');
if ($about !== null) {
    $r = CmsPageService::deleteForAdmin((int) $about['id']);
    t(!$r['ok'] && strpos($r['message'], 'system') !== false, 'Protected CMS page cannot be deleted');
}

// Custom CMS page delete
if (CmsPageService::schemaReady()) {
    $ins = $pdo->prepare('INSERT INTO cms_pages (title, slug, body_html, status, show_in_footer, visibility) VALUES (?,?,?,?,0,? )');
    $slug = 'zt-del-cms-' . $tok;
    $ins->execute(['ZT Del CMS', $slug, '<p>x</p>', PublishStatus::DRAFT, 'public']);
    $cmsId = (int) $pdo->lastInsertId();
    $r = CmsPageService::deleteForAdmin($cmsId);
    t($r['ok'] && CmsPageRepository::findById($cmsId) === null, 'Custom CMS page deletes successfully');
}

// Category: child blocks delete
$par = CategoryRepository::save(null, ['name' => 'ZT Del Par', 'slug' => 'zt-del-par-' . $tok, 'status' => 'published']);
$kid = CategoryRepository::save(null, ['name' => 'ZT Del Kid', 'slug' => 'zt-del-kid-' . $tok, 'parent_id' => (string) $par['id'], 'status' => 'published']);
$r = CategoryRepository::deleteForAdmin((int) $par['id']);
t(!$r['ok'] && strpos($r['message'], 'child') !== false, 'Category with children cannot be deleted');

// Category leaf delete
$leaf = CategoryRepository::save(null, ['name' => 'ZT Del Leaf', 'slug' => 'zt-del-leaf-' . $tok, 'status' => 'published']);
$r2 = CategoryRepository::deleteForAdmin((int) $leaf['id']);
t($r2['ok'], 'Leaf category deletes when empty');

// Product archive when order history exists
$catLeaf = CategoryRepository::save(null, ['name' => 'ZT Del PCat', 'slug' => 'zt-del-pcat-' . $tok, 'status' => 'published']);
$pr = ProductRepository::saveGeneral(null, ['name' => 'ZT Del Prod', 'slug' => 'zt-del-prod-' . $tok, 'category_id' => (string) $catLeaf['id'], 'status' => 'published', 'sku' => 'ZT-DP-' . $tok]);
$pid = (int) $pr['id'];
ProductRepository::saveVariants($pid, [['name' => 'Std', 'price' => 10, 'stock_qty' => 1, 'low_stock_threshold' => 1, 'is_active' => 1]]);
$vars = ProductRepository::variants($pid);
$vid = (int) ($vars[0]['id'] ?? 0);
$cust = $pdo->prepare('INSERT INTO customers (mobile, name, is_active) VALUES (?,?,1)');
$cust->execute(['9' . random_int(100000000, 999999999), 'ZT Del Cust']);
$cid = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO orders (order_number, order_source, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())')
    ->execute(['ZT-DEL-' . $tok, 'storefront', $cid, 'confirmed', 10, 0, 0, 10, 'paid']);
$oid = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, variant_id, product_name, variant_name, sku, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?,?,?)')
    ->execute([$oid, $vid, 'ZT Del Prod', 'Std', 'ZT-DP-' . $tok, 1, 10, 10]);
$r3 = ProductRepository::deleteOrArchiveForAdmin($pid);
t(!empty($r3['ok']) && !empty($r3['archived']) && ProductRepository::find($pid)['status'] === 'archived', 'Product with order history archives instead of hard delete');
t((int) $pdo->query('SELECT COUNT(*) FROM order_items WHERE order_id = ' . $oid)->fetchColumn() === 1, 'Order items preserved after product archive');

// Coupon archive when used
$couponIns = $pdo->prepare('INSERT INTO coupons (code, internal_name, discount_type, discount_value, is_active) VALUES (?,?,?,?,1)');
$couponIns->execute(['ZT' . strtoupper($tok), 'ZT', 'fixed', 5]);
$coupId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO coupon_usages (coupon_id, order_id, customer_id) VALUES (?,?,?)')->execute([$coupId, $oid, $cid]);
$r4 = CouponRepository::deleteForAdmin($coupId);
t(!empty($r4['ok']) && !empty($r4['archived']) && (int) CouponRepository::find($coupId)['is_active'] === 0, 'Used coupon deactivates instead of delete');

// Offer delete
$offIns = $pdo->prepare('INSERT INTO offers (name, scope, target_id, discount_type, discount_value, priority, stack_with_coupons, is_active) VALUES (?,?,?,?,?,?,?,1)');
$offIns->execute(['ZT Off ' . $tok, 'cart', null, 'fixed', 1, 0, 0]);
$offId = (int) $pdo->lastInsertId();
t(OfferRepository::deleteForAdmin($offId)['ok'], 'Offer deletes when unused');

// FAQ delete
$faqCat = FaqRepository::insertCategory(['name' => 'ZT FC', 'slug' => 'zt-fc-' . $tok, 'sort_order' => 0, 'is_active' => 1]);
$faqId = FaqRepository::insertFaq(['category_id' => $faqCat, 'question' => 'Q?', 'answer' => 'A', 'sort_order' => 0, 'is_active' => 1]);
t(FaqService::deleteFaq($faqId)['ok'], 'FAQ deletes');

// UI wiring
$la = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/admin/list_actions.php');
t(strpos($la, 'admin_delete') !== false && strpos($la, 'confirm(') !== false && strpos($la, 'Csrf::field') !== false, 'Delete helper uses POST, CSRF, confirm');
t(strpos($la, 'at-admin-actions__btn--delete') !== false && strpos($la, 'trash-2') !== false, 'Delete uses icon button markup');
t(strpos($la, 'admin_action_edit_link') !== false && strpos($la, 'pencil') !== false, 'Edit uses pencil icon button');
t(strpos((string) file_get_contents(APNI_TOKRI_ROOT . '/admin/cms/index.php'), 'admin_process_list_delete') !== false, 'CMS index wires delete handler');
t(strpos((string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php'), "'raw'") !== false, 'Data table supports raw actions column');

$prevMethod = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_POST['admin_delete']);
t(admin_process_list_delete('cms', 'http://example.test/') === false, 'GET request does not run delete handler');
$_SERVER['REQUEST_METHOD'] = $prevMethod;

$tBad = admin_delete_entity('unknown_entity', 1);
t(!$tBad['ok'], 'Unknown entity rejected');
$tZero = admin_delete_entity('product', 0);
t(!$tZero['ok'], 'Invalid record id rejected');

// Cleanup
$pdo->exec("DELETE FROM coupon_usages WHERE coupon_id = $coupId");
$pdo->exec("DELETE FROM coupons WHERE id = $coupId OR code LIKE 'ZT%'");
$pdo->exec("DELETE FROM order_items WHERE order_id = $oid");
$pdo->exec("DELETE FROM orders WHERE id = $oid");
$pdo->exec("DELETE FROM customers WHERE id = $cid");
$pdo->exec("DELETE FROM products WHERE slug LIKE 'zt-del-%'");
$pdo->exec("DELETE FROM categories WHERE slug LIKE 'zt-del-%' OR slug LIKE 'zt-fc-%'");
$pdo->exec("DELETE FROM faq_categories WHERE slug LIKE 'zt-fc-%'");

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
