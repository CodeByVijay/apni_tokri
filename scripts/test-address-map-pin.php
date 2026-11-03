<?php

declare(strict_types=1);

/**
 * Delivery address + map pin: validation, persistence, order snapshot immutability, security.
 * Creates throw-away customers/orders and removes them afterwards.
 * Run: php scripts/test-address-map-pin.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
Install::ensureConnection();
$pdo = Database::pdo();
$col = $pdo->query("SHOW COLUMNS FROM customer_addresses LIKE 'location_confirmed_at'")->fetch();
if (!$col) {
    fwrite(STDERR, "Apply database/migrations/1.19.0_address_map_pin.sql first.\n");
    exit(1);
}

$fail = 0;
function ok(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $l . "\n";
    if (!$c) {
        $fail++;
    }
}

$root = dirname(__DIR__);
$tag = substr(md5((string) microtime(true)), 0, 6);
$mk = static function (string $mobile, string $name) use ($pdo): int {
    $pdo->prepare('DELETE FROM customers WHERE mobile = ?')->execute([$mobile]);
    $pdo->prepare('INSERT INTO customers (mobile, name, is_active) VALUES (?, ?, 1)')->execute([$mobile, $name]);

    return (int) $pdo->lastInsertId();
};
$A = $mk('9000000021', 'Map Test A');
$B = $mk('9000000022', 'Map Test B');
$orderNumbers = [];
// Checkout creates the order, then asks the active payment gateway to start. Use the simulator for the test
// and restore the original setting afterwards.
$origGateway = AppSettings::get('payment.active_gateway', null);
AppSettings::set('payment.active_gateway', 'simulate');

$good = static function (array $over = []): array {
    return array_merge([
        'label_type' => 'Home', 'house_number' => 'Flat 402', 'building_name' => 'Sunrise Apartments',
        'street' => 'MG Road', 'area' => 'Vijay Nagar', 'pincode' => '452010', 'landmark' => 'Near City Mall',
        'delivery_instructions' => 'Call at gate', 'receiver_name' => 'Asha Verma', 'receiver_phone' => '9876543210',
        'alternate_phone' => '', 'latitude' => '22.7533210', 'longitude' => '75.8937220',
        'location_confirmed' => '1', 'address_confirmed' => '1',
    ], $over);
};
$row = static function (int $cid, int $id) {
    return CustomerAccountService::findAddress($cid, $id);
};

try {
    // ---- create: valid + coordinates
    $r = CustomerAccountService::addAddress($A, $good(), true);
    ok(!empty($r['ok']) && !empty($r['id']), 'create structured address with pin');
    $a1 = (int) ($r['id'] ?? 0);
    $ra = $row($A, $a1);
    ok($ra !== null && abs((float) $ra['latitude'] - 22.753321) < 1e-6 && abs((float) $ra['longitude'] - 75.893722) < 1e-6, 'coordinates saved at 7dp precision');
    ok($ra !== null && !empty($ra['location_confirmed_at']), 'location_confirmed_at set when pin confirmed');
    ok($ra !== null && $ra['house_number'] === 'Flat 402' && $ra['receiver_phone'] === '9876543210' && $ra['label'] === 'Home', 'structured fields + receiver stored');
    ok($ra !== null && strpos((string) $ra['line1'], 'Flat 402') !== false && (string) $ra['line2'] === 'Near City Mall', 'line1/line2 composed for legacy consumers');

    // ---- create: Other label
    $o = CustomerAccountService::addAddress($A, $good(['label_type' => 'Other', 'label_custom' => "Mom's place"]));
    ok(!empty($o['ok']) && $row($A, (int) $o['id'])['label'] === "Mom's place", 'Other label uses custom text');
    ok(isset(CustomerAccountService::addAddress($A, $good(['label_type' => 'Other', 'label_custom' => '']))['errors']['label']), 'Other without custom label rejected');
    $oid = (int) $o['id'];

    // ---- missing required fields
    foreach (['house_number', 'building_name', 'street', 'area', 'pincode', 'receiver_name', 'receiver_phone'] as $k) {
        $e = CustomerAccountService::addAddress($A, $good([$k => '']));
        ok(empty($e['ok']) && isset($e['errors'][$k]), "missing {$k} rejected");
    }
    ok(isset(CustomerAccountService::addAddress($A, $good(['address_confirmed' => '']))['errors']['confirm']), 'explicit address confirmation required');

    // ---- invalid pincode / phone
    ok(isset(CustomerAccountService::addAddress($A, $good(['pincode' => '45201']))['errors']['pincode']), 'invalid pincode format rejected');
    ok(isset(CustomerAccountService::addAddress($A, $good(['pincode' => '110001']))['errors']['pincode']), 'non-serviceable pincode rejected');
    ok(isset(CustomerAccountService::addAddress($A, $good(['receiver_phone' => '1234567890']))['errors']['receiver_phone']), 'invalid receiver phone rejected');
    ok(isset(CustomerAccountService::addAddress($A, $good(['receiver_phone' => '98765']))['errors']['receiver_phone']), 'short receiver phone rejected');
    ok(isset(CustomerAccountService::addAddress($A, $good(['alternate_phone' => 'abc']))['errors']['alternate_phone']), 'invalid alternate phone rejected');
    ok(!empty(CustomerAccountService::addAddress($A, $good(['alternate_phone' => '+91 98765 43211']))['ok']), 'valid alternate phone (+91 format) accepted');

    // ---- coordinate validation
    $before = (int) $pdo->query('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ' . $A)->fetchColumn();
    foreach ([
        'lat 91' => ['latitude' => '91', 'longitude' => '75.8'],
        'lat -91' => ['latitude' => '-91', 'longitude' => '75.8'],
        'lng 181' => ['latitude' => '22.7', 'longitude' => '181'],
        'lng -181' => ['latitude' => '22.7', 'longitude' => '-181'],
        'non-numeric' => ['latitude' => 'abc', 'longitude' => '75.8'],
        'sql-ish' => ['latitude' => "22.7'; DROP TABLE x;--", 'longitude' => '75.8'],
        'exponent' => ['latitude' => '1e2', 'longitude' => '75.8'],
        'half pin' => ['latitude' => '22.7', 'longitude' => ''],
        'null island' => ['latitude' => '0', 'longitude' => '0'],
    ] as $name => $c) {
        $e = CustomerAccountService::addAddress($A, $good($c));
        ok(empty($e['ok']) && isset($e['errors']['location']), "coordinate rejected: {$name}");
    }
    ok((int) $pdo->query('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ' . $A)->fetchColumn() === $before, 'rejected coordinates created no rows');
    $e = CustomerAccountService::addAddress($A, $good(['location_confirmed' => '']));
    ok(empty($e['ok']) && isset($e['errors']['location']), 'coordinates without map confirmation rejected');
    ok(AddressInput::parseCoordinates('-90', '180')['error'] === null, 'boundary lat -90 / lng 180 valid');

    // ---- missing coordinates allowed (pin optional) -> pin missing
    $np = CustomerAccountService::addAddress($A, $good(['latitude' => '', 'longitude' => '', 'location_confirmed' => '']));
    $npId = (int) ($np['id'] ?? 0);
    $rnp = $row($A, $npId);
    ok(!empty($np['ok']) && $rnp !== null && $rnp['latitude'] === null && $rnp['longitude'] === null && $rnp['location_confirmed_at'] === null, 'address without pin saved with NULL coordinates');
    ok(!AddressInput::hasPin($rnp), 'hasPin false for address without coordinates');

    // ---- legacy address (old form shape) still works and can gain a pin
    $leg = CustomerAccountService::addAddress($A, ['label' => 'Old', 'line1' => '5 Legacy Rd', 'pincode' => '452010']);
    ok(!empty($leg['ok']), 'legacy-shaped address still accepted');
    $legId = (int) $leg['id'];
    $up = CustomerAccountService::updateAddress($A, $legId, $good(['label_type' => 'Home']));
    $rl = $row($A, (int) $up['id']);
    ok(!empty($up['ok']) && $rl !== null && AddressInput::hasPin($rl), 'legacy address upgraded with structured fields + pin');

    // ---- edit: change pin only, in place
    $ed = CustomerAccountService::updateAddress($A, $a1, $good(['latitude' => '22.7600000', 'longitude' => '75.9000000']));
    ok(!empty($ed['ok']) && (int) $ed['id'] === $a1 && abs((float) $row($A, $a1)['latitude'] - 22.76) < 1e-6, 'edit changes pin of unreferenced address in place');
    $ed2 = CustomerAccountService::updateAddress($A, $a1, $good(['latitude' => '22.7600000', 'longitude' => '75.9000000', 'receiver_name' => 'New Receiver']));
    ok(!empty($ed2['ok']) && $row($A, $a1)['receiver_name'] === 'New Receiver', 'edit receiver + instructions');
    ok(!empty($row($A, $a1)['location_confirmed_at']), 'unchanged pin keeps confirmation');
    $ed3 = CustomerAccountService::updateAddress($A, $a1, $good(['latitude' => '22.7', 'longitude' => '75.8', 'location_confirmed' => '']));
    ok(empty($ed3['ok']) && abs((float) $row($A, $a1)['latitude'] - 22.76) < 1e-6, 'edit with unconfirmed new pin rejected, old pin kept');

    // ---- default
    CustomerAccountService::setDefault($A, $oid);
    $defs = (int) $pdo->query('SELECT COUNT(*) FROM customer_addresses WHERE archived_at IS NULL AND is_default = 1 AND customer_id = ' . $A)->fetchColumn();
    ok($defs === 1 && (int) $row($A, $oid)['is_default'] === 1, 'exactly one default after change');

    // ---- ownership isolation
    $x = CustomerAccountService::updateAddress($B, $a1, $good(['receiver_name' => 'Hijack']));
    ok(empty($x['ok']) && $row($A, $a1)['receiver_name'] === 'New Receiver', 'B cannot edit A address / pin');
    ok($row($B, $a1) === null, 'B cannot read A address');
    ok(empty(CustomerAccountService::deleteAddress($B, $a1)['ok']) && $row($A, $a1) !== null, 'B cannot delete A address');
    ok(CustomerService::address($a1, $B) === null, 'checkout address lookup scoped to owner');
    $forged = CustomerAccountService::addAddress($B, $good(['customer_id' => (string) $A]));
    $fr = $pdo->prepare('SELECT customer_id FROM customer_addresses WHERE id = ?');
    $fr->execute([(int) $forged['id']]);
    ok(!empty($forged['ok']) && (int) $fr->fetchColumn() === $B, 'tampered customer_id in POST ignored (row belongs to session customer)');

    // ---- snapshot + immutability
    $_SESSION = [];
    $_SESSION['customer_id'] = $A;
    $addrRow = CustomerService::address($a1, $A);
    $snap = DeliveryAddressSnapshot::fromAddressRow($addrRow, CustomerService::profile());
    ok($snap['latitude'] === 22.76 && $snap['longitude'] === 75.9 && $snap['location_confirmed'] === true, 'snapshot carries confirmed coordinates');
    ok($snap['receiver_name'] === 'New Receiver' && $snap['receiver_phone'] === '9876543210' && $snap['house_number'] === 'Flat 402', 'snapshot carries receiver + structured fields');
    $legSnap = DeliveryAddressSnapshot::fromAddressRow($rnp, ['name' => 'Fallback', 'mobile' => '9000000021']);
    ok($legSnap['latitude'] === null && $legSnap['longitude'] === null && $legSnap['location_confirmed'] === false, 'snapshot without pin has null coordinates');
    $noRecv = DeliveryAddressSnapshot::fromAddressRow(['label' => 'Old', 'line1' => 'x', 'pincode' => '452010'], ['name' => 'Fallback', 'mobile' => '9000000021']);
    ok($noRecv['receiver_name'] === 'Fallback' && !empty($noRecv['receiver_from_account']), 'legacy address falls back to account name/phone');

    // place a real order if catalogue stock exists
    $variant = $pdo->query(
        "SELECT p.slug, pv.id AS vid FROM product_variants pv JOIN products p ON p.id = pv.product_id
         WHERE p.status = 'published' AND pv.is_active = 1 ORDER BY pv.id ASC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);
    $placed = null;
    $orderNo = '';
    foreach ($variant as $v) {
        if (InventoryService::availableQty((int) $v['vid']) < 1) {
            continue;
        }
        CartService::clear();
        $add = CartService::add((string) $v['slug'], (string) $v['vid'], 1);
        if (empty($add['ok'])) {
            continue;
        }
        $slots = DeliveryConfig::slotsEnabled() ? DeliverySlotService::availableForCheckout() : [];
        $slot = DeliveryConfig::slotsEnabled() ? (string) ($slots[0]['slug'] ?? '') : 'auto';
        $nonce = CheckoutService::freshNonce();
        $placed = CheckoutService::placeOrder([
            'checkout_nonce' => $nonce, 'address_id' => (string) $a1, 'delivery_slot' => $slot,
            'terms' => '1', 'payment_sim' => 'success',
        ]);
        if (!empty($placed['ok'])) {
            $orderNo = (string) ($placed['order_number'] ?? '');
            $orderNumbers[] = $orderNo;
            break;
        }
    }
    if ($orderNo === '') {
        ok(false, 'could not place a test order (needs an in-stock active product): ' . json_encode($placed));
    } else {
        ok(true, 'order placed via CheckoutService::placeOrder: ' . $orderNo);
        $os = $pdo->prepare('SELECT id, customer_id, customer_address_id, delivery_snapshot_json FROM orders WHERE order_number = ?');
        $os->execute([$orderNo]);
        $ord = $os->fetch(PDO::FETCH_ASSOC);
        $js = json_decode((string) $ord['delivery_snapshot_json'], true);
        $sa = is_array($js) ? ($js['address'] ?? null) : null;
        ok(is_array($sa) && $sa['latitude'] === 22.76 && $sa['longitude'] === 75.9, 'order snapshot stores pin coordinates');
        ok(is_array($sa) && $sa['receiver_name'] === 'New Receiver' && $sa['pincode'] === '452010', 'order snapshot stores receiver + pincode');
        $snapBefore = (string) $ord['delivery_snapshot_json'];

        // edit the saved address after the order: referenced row is archived, order untouched
        $chg = CustomerAccountService::updateAddress($A, $a1, $good([
            'house_number' => 'Flat 999', 'latitude' => '22.7000000', 'longitude' => '75.8000000', 'receiver_name' => 'Someone Else',
        ]));
        ok(!empty($chg['ok']) && (int) $chg['id'] !== $a1, 'editing order-referenced address creates a new row');
        $os->execute([$orderNo]);
        $after = $os->fetch(PDO::FETCH_ASSOC);
        ok((string) $after['delivery_snapshot_json'] === $snapBefore, 'order snapshot JSON byte-identical after address edit');
        $forOrder = DeliveryAddressSnapshot::forOrder($after, $pdo);
        ok($forOrder !== null && $forOrder['latitude'] === 22.76 && $forOrder['receiver_name'] === 'New Receiver' && empty($forOrder['legacy_order']), 'forOrder returns original snapshot, not edited address');
        $ar = $pdo->prepare('SELECT house_number, latitude FROM customer_addresses WHERE id = ?');
        $ar->execute([$a1]);
        $arr = $ar->fetch(PDO::FETCH_ASSOC);
        ok($arr['house_number'] === 'Flat 402' && abs((float) $arr['latitude'] - 22.76) < 1e-6, 'archived original address row unchanged');

        // checkout server-side: unserviceable pincode / foreign address / archived address
        $_SESSION['customer_id'] = $B;
        CartService::clear();
        ok(CheckoutService::placeOrder(['address_id' => (string) $a1, 'terms' => '1']) !== null, 'placeOrder with another customer address returns a result');
        $_SESSION['customer_id'] = $A;
        $v0 = $variant[0] ?? null;
        if ($v0 !== null) {
            CartService::clear();
            CartService::add((string) $v0['slug'], (string) $v0['vid'], 1);
            $nonce = CheckoutService::freshNonce();
            $res = CheckoutService::placeOrder(['checkout_nonce' => $nonce, 'address_id' => (string) $a1, 'delivery_slot' => 'auto', 'terms' => '1']);
            ok(empty($res['ok']), 'archived (order-referenced) address cannot be used at checkout');
            $_SESSION['customer_id'] = $B;
            CartService::clear();
            CartService::add((string) $v0['slug'], (string) $v0['vid'], 1);
            $nonce = CheckoutService::freshNonce();
            $res = CheckoutService::placeOrder(['checkout_nonce' => $nonce, 'address_id' => (string) $oid, 'delivery_slot' => 'auto', 'terms' => '1']);
            ok(empty($res['ok']), "B cannot order to A's address id");
            $_SESSION['customer_id'] = $A;
        }

        // admin helper uses the snapshot
        $adm = AdminOrderService::findById((int) $ord['id']);
        ok($adm !== null && strpos((string) $adm['address_line'], 'Flat 402') !== false && strpos((string) $adm['address_line'], 'Flat 999') === false, 'admin order view shows snapshot address');

        // courier payload minimal + staff only
        $pl = DeliveryAddressSnapshot::courierPayload($forOrder);
        ok(isset($pl['latitude'], $pl['longitude'], $pl['map_link']) && strpos((string) $pl['map_link'], 'openstreetmap.org') !== false, 'courier payload has coordinates + OSM link');
        ok(!isset($pl['customer_id']) && !isset($pl['email']), 'courier payload excludes unrelated customer data');
        ok(strpos(DeliveryAddressSnapshot::courierText($forOrder, $orderNo), $orderNo) !== false, 'courier text includes order number');
    }

    // ---- legacy order without snapshot address: falls back, no crash
    $pdo->prepare("INSERT INTO orders (order_number, customer_id, customer_address_id, status, delivery_snapshot_json) VALUES (?, ?, ?, 'confirmed', ?)")
        ->execute(["AT-MAP-LEG-{$tag}", $A, $npId, json_encode(['pincode' => '452010'])]);
    $orderNumbers[] = "AT-MAP-LEG-{$tag}";
    $lo = $pdo->prepare('SELECT * FROM orders WHERE order_number = ?');
    $lo->execute(["AT-MAP-LEG-{$tag}"]);
    $legOrder = $lo->fetch(PDO::FETCH_ASSOC);
    $legA = DeliveryAddressSnapshot::forOrder($legOrder, $pdo);
    ok($legA !== null && !empty($legA['legacy_order']) && $legA['latitude'] === null, 'legacy order (no address block, no coords) resolves safely');
    $pdo->prepare("INSERT INTO orders (order_number, customer_id, status) VALUES (?, ?, 'confirmed')")->execute(["AT-MAP-NOADDR-{$tag}", $A]);
    $orderNumbers[] = "AT-MAP-NOADDR-{$tag}";
    $lo->execute(["AT-MAP-NOADDR-{$tag}"]);
    $nr = $lo->fetch(PDO::FETCH_ASSOC);
    ok(DeliveryAddressSnapshot::forOrder($nr, $pdo) === null || is_array(DeliveryAddressSnapshot::forOrder($nr, $pdo)), 'order with no address at all does not crash');

    // ---- delete/archive
    $d = CustomerAccountService::deleteAddress($A, $npId);
    $stillRef = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE customer_address_id = {$npId}")->fetchColumn() > 0;
    ok(!empty($d['ok']) && ($stillRef ? !empty($d['archived']) : $row($A, $npId) === null), 'delete: archived when referenced, removed otherwise');
    $d2 = CustomerAccountService::deleteAddress($A, $oid);
    ok(!empty($d2['ok']) && $row($A, $oid) === null, 'unreferenced address deleted');

    // ---- max address limit
    $limit = CustomerAccountService::MAX_ADDRESSES ?? null;
    if (is_int($limit)) {
        $cur = (int) $pdo->query('SELECT COUNT(*) FROM customer_addresses WHERE archived_at IS NULL AND customer_id = ' . $A)->fetchColumn();
        for ($i = $cur; $i < $limit; $i++) {
            CustomerAccountService::addAddress($A, $good(['house_number' => 'F' . $i]));
        }
        ok(empty(CustomerAccountService::addAddress($A, $good())['ok']), 'max address count enforced');
    } else {
        echo "SKIP: max address constant not exposed\n";
    }

    // ---- output / config / security (static)
    $page = (string) file_get_contents($root . '/my-addresses.php');
    $chk = (string) file_get_contents($root . '/checkout.php');
    ok(strpos($page, 'Csrf::requireValid()') !== false && strpos($chk, 'Csrf::requireValid()') !== false, 'address POST handlers require CSRF');
    ok(strpos($page, "\$_POST['customer_id']") === false && strpos($chk, "\$_POST['customer_id']") === false, 'pages never read customer_id from POST');
    $form = (string) file_get_contents($root . '/includes/partials/address-form.php');
    ok(strpos($form, 'Csrf::field()') !== false, 'address form includes CSRF field');
    $js = (string) file_get_contents($root . '/assets/js/address-map.js');
    ok(preg_match('/navigator\.geolocation\.getCurrentPosition/', $js) === 1 && strpos($js, 'watchPosition') === false, 'geolocation requested once per click (no watch)');
    ok(!preg_match('/maps\.googleapis|api\.mapbox|google\.com\/maps|[?&]key=/i', $js . $form . (string) file_get_contents($root . '/includes/map/config.php')), 'no Google/Mapbox/API key references');
    $cfg = MapConfig::settings();
    ok(strpos($cfg['tile_url'], 'tile.openstreetmap.org') !== false && strpos($cfg['attribution'], 'OpenStreetMap') !== false, 'default tiles are OSM with attribution');
    $client = json_encode(MapConfig::clientConfig());
    ok(strpos((string) $client, 'api_key') === false && strpos((string) $client, 'apikey') === false, 'client map config has no key');
    ok(is_file($root . '/assets/vendor/leaflet/leaflet.js') && is_file($root . '/assets/vendor/leaflet/leaflet.css'), 'Leaflet hosted locally');

    // no public endpoint exposes coordinates
    $leak = [];
    foreach (['api', 'ajax', '.'] as $dir) {
        $base = $root . ($dir === '.' ? '' : '/' . $dir);
        if (!is_dir($base)) {
            continue;
        }
        foreach (glob($base . '/*.php') ?: [] as $f) {
            $s = (string) file_get_contents($f);
            if (preg_match('/\b(latitude|longitude)\b/', $s) && basename($f) !== 'my-addresses.php' && basename($f) !== 'checkout.php') {
                $leak[] = basename($f);
            }
        }
    }
    ok($leak === [], 'no other public root/api php file references coordinates (' . implode(',', $leak) . ')');
} finally {
    AppSettings::set('payment.active_gateway', $origGateway);
    foreach ($orderNumbers as $on) {
        $oi = $pdo->prepare('SELECT id FROM orders WHERE order_number = ?');
        $oi->execute([$on]);
        $oidd = (int) $oi->fetchColumn();
        if ($oidd > 0) {
            foreach (['order_items', 'payments', 'order_status_history', 'delivery_assignments'] as $t) {
                try {
                    $pdo->prepare("DELETE FROM {$t} WHERE order_id = ?")->execute([$oidd]);
                } catch (Throwable $e) {
                    // table may not exist / different FK; ignore
                }
            }
        }
        try {
            $pdo->prepare('DELETE FROM orders WHERE order_number = ?')->execute([$on]);
        } catch (Throwable $e) {
            echo "WARN: could not delete order {$on}: " . $e->getMessage() . "\n";
        }
    }
    foreach ([$A, $B] as $cid) {
        try {
            $pdo->prepare('DELETE FROM customer_addresses WHERE customer_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
        } catch (Throwable $e) {
            echo "WARN: cleanup customer {$cid}: " . $e->getMessage() . "\n";
        }
    }
}

echo $fail === 0 ? "\nPASS: address map pin tests\n" : "\nFAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
