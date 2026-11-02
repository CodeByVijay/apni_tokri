<?php

declare(strict_types=1);

/**
 * Two-connection concurrency: subscription-style slot lock (cap=1).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady() || !DeliverySlotReservationService::tableReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$config = App::config()['database'];
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['host'] ?? '127.0.0.1',
    (int) ($config['port'] ?? 3306),
    $config['name'],
    $config['charset'] ?? 'utf8mb4'
);
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

$pdoA = new PDO($dsn, (string) $config['user'], (string) $config['password'], $opts);
$pdoA->exec("SET time_zone = '+05:30'");
$pdoB = new PDO($dsn, (string) $config['user'], (string) $config['password'], $opts);
$pdoB->exec("SET time_zone = '+05:30'");

$slug = 'conc_sub_' . substr(Security::randomToken(3), 0, 5);
$pdoA->prepare(
    'INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity, sort_order, is_active, weekday_mask)
     VALUES (?,?,?,?,?,?,?,?,?,?,127)'
)->execute([$slug, 'Conc', 'C', 'same_day', '23:55:00', '23:59:00', '23:58:00', 1, 9997, 1]);
$slotId = (int) $pdoA->lastInsertId();
$date = DeliveryConfig::now()->format('Y-m-d');

$fail = 0;
try {
    $pdoA->beginTransaction();
    DeliverySlotReservationService::lockSlotAndAssertCapacity($pdoA, $slotId, $date, null);

    $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $pdoB->beginTransaction();
    $blocked = false;
    try {
        DeliverySlotReservationService::lockSlotAndAssertCapacity($pdoB, $slotId, $date, null);
    } catch (DeliverySlotBookingException $e) {
        $blocked = true;
    } catch (PDOException $e) {
        // InnoDB waits on the slot row FOR UPDATE held by connection A.
        if ((int) ($e->errorInfo[1] ?? 0) === 1205) {
            $blocked = true;
        } else {
            throw $e;
        }
    }
    if ($pdoB->inTransaction()) {
        $pdoB->rollBack();
    }
    if (!$blocked) {
        echo "FAIL: second connection should not acquire full slot while first holds row lock\n";
        $fail++;
    } else {
        echo "OK: second connection blocked while first holds lock\n";
    }
    $pdoA->commit();
} catch (Throwable $e) {
    if ($pdoA->inTransaction()) {
        $pdoA->rollBack();
    }
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

$pdoA->prepare('DELETE FROM delivery_slots WHERE id = ?')->execute([$slotId]);

exit($fail > 0 ? 1 : 0);
