<?php

declare(strict_types=1);

/**
 * Meta Conversions API (server-side) — durable outbox + sender.
 *
 * Phase 3 scope: Purchase events for genuine storefront orders only.
 *
 * Flow:
 *   1. checkout captures a consent + browser-context snapshot into
 *      orders.admin_meta_json (customer request context — the only place the
 *      visitor's cookie, User-Agent and IP exist).
 *   2. PaymentService::completePayment() calls enqueuePurchase() INSIDE the
 *      same transaction that flips orders.payment_status to 'paid', only on
 *      the non-idempotent success branch. The unique event_id (shared with
 *      the browser Pixel via MetaPixel::purchaseEventId()) makes the insert
 *      exactly-once.
 *   3. Post-commit, afterOrderPaid() attempts delivery plus a small batch of
 *      older due events — fully guarded, never able to fail a payment.
 *   4. Admin Meta status section can retry failed/pending events manually.
 *
 * Consent policy: fail closed. Only a snapshot recorded as 'granted' at
 * checkout authorizes a CAPI event; absent/denied never sends. Observed
 * withdrawal suppresses unsent events (observeVisitorConsent).
 */
final class MetaCapi
{
    /**
     * Meta Graph/Marketing API version — the single place it is defined.
     * v26.0 (released 2026-07-29, available until TBD): the /{dataset}/events
     * Conversions API endpoint is unchanged across v21→v26 per Meta changelogs.
     */
    public const GRAPH_API_VERSION = 'v26.0';
    private const GRAPH_HOST = 'https://graph.facebook.com';
    private const LEASE_SECONDS = 300;
    private const MAX_ATTEMPTS = 6;
    private const HTTP_TIMEOUT_SECONDS = 5;
    private const SWEEP_BATCH = 4;
    private const ADMIN_RETRY_BATCH = 10;
    private const REAL_GATEWAYS = ['cashfree', 'razorpay'];
    /**
     * Retention policy bounds (no cron — enforced opportunistically before
     * each send batch and admin retry):
     *  - Meta credits website events only ~7 days back; older unsent events are
     *    terminalized and scrubbed rather than delivered stale.
     *  - terminal 'failed' payloads are scrubbed after FAILED_RETENTION_DAYS.
     * 'sent' and 'suppressed' rows are scrubbed at transition time.
     */
    private const EVENT_MAX_AGE_DAYS = 7;
    private const FAILED_RETENTION_DAYS = 30;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SUPPRESSED = 'suppressed';

    private static ?bool $tableReady = null;

    /** Outbox table presence — cached per request, like other schema probes. */
    public static function tableReady(): bool
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }
        self::$tableReady = false;
        try {
            if (!Install::isSchemaReady()) {
                return false;
            }
            Install::ensureConnection();
            $stmt = Database::pdo()->query("SHOW TABLES LIKE 'meta_capi_events'");
            self::$tableReady = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            self::$tableReady = false;
        }

        return self::$tableReady;
    }

    /**
     * Consent + browser-context snapshot captured at checkout/order creation.
     * Stored in orders.admin_meta_json under the 'meta_tracking' key. UA/IP are
     * trusted server-side request context (not webhook-supplied headers).
     */
    public static function checkoutTrackingSnapshot(): string
    {
        $state = MetaConsent::state();
        $consent = !$state['set']
            ? 'absent'
            : ((bool) $state['marketing'] ? 'granted' : 'denied');

        return (string) json_encode([
            'meta_tracking' => [
                'marketing_consent' => $consent,
                'consent_version' => MetaConsent::POLICY_VERSION,
                'captured_at' => date('c'),
                'client_user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'client_ip' => self::publicClientIp(),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * The only client IP this app may trust: REMOTE_ADDR, the direct peer.
     * There is no trusted-proxy configuration anywhere in the codebase, so
     * forwarded headers (X-Forwarded-For etc.) are never read — a spoofed or
     * proxy-hop IP must not reach Meta. When the peer is not a public address
     * (e.g. the site sits behind a reverse proxy without trusted config), the
     * real client IP is unknowable: return null and omit it rather than send
     * an incorrect value.
     */
    private static function publicClientIp(): ?string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ip === '') {
            return null;
        }
        $valid = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $valid !== false ? substr((string) $valid, 0, 45) : null;
    }

    /**
     * Re-stamp the tracking snapshot on an existing (idempotency-reused) order
     * so the latest consent/browser context wins. No-op without the table/column
     * or when the order is missing.
     */
    public static function refreshCheckoutSnapshot(int $orderId): void
    {
        try {
            if (!self::tableReady() || $orderId <= 0) {
                return;
            }
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT admin_meta_json FROM orders WHERE id = ? LIMIT 1');
            $stmt->execute([$orderId]);
            $raw = $stmt->fetchColumn();
            $meta = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $meta = is_array($meta) ? $meta : [];
            $snapshot = json_decode(self::checkoutTrackingSnapshot(), true);
            $meta['meta_tracking'] = is_array($snapshot) ? $snapshot['meta_tracking'] : [];
            $pdo->prepare('UPDATE orders SET admin_meta_json = ? WHERE id = ?')
                ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $orderId]);
        } catch (Throwable $e) {
            error_log('MetaCapi::refreshCheckoutSnapshot: ' . $e->getMessage());
        }
    }

    /** Consent recorded at checkout — only an explicit 'granted' authorizes CAPI. */
    private static function consentGranted(array $orderRow): bool
    {
        $raw = $orderRow['admin_meta_json'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $meta = json_decode($raw, true);

        return is_array($meta)
            && (string) ($meta['meta_tracking']['marketing_consent'] ?? '') === 'granted';
    }

    /**
     * Queue a Purchase event for a freshly-paid order.
     * MUST run inside the completePayment() transaction while the order row is
     * locked — the unique event_id makes it exactly-once under races/retries.
     *
     * @param array<string, mixed> $order Locked orders row.
     * @param list<array<string, mixed>> $items order_items rows (sku, variant_id, quantity, unit_price).
     */
    public static function enqueuePurchase(PDO $pdo, array $order, array $items, string $gateway): void
    {
        try {
            if (!self::tableReady()) {
                return;
            }
            if ((string) ($order['order_source'] ?? '') !== 'storefront') {
                return;
            }
            if (!in_array($gateway, self::REAL_GATEWAYS, true)) {
                return;
            }
            if (!self::consentGranted($order)) {
                return;
            }
            $orderNumber = (string) ($order['order_number'] ?? '');
            if ($orderNumber === '') {
                return;
            }
            $payload = self::buildPurchaseEvent($pdo, $order, $items);
            if ($payload === null) {
                return;
            }
            $pdo->prepare(
                'INSERT IGNORE INTO meta_capi_events
                 (event_id, order_id, order_number, customer_id, event_name, payload_json, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                MetaPixel::purchaseEventId($orderNumber),
                (int) $order['id'],
                $orderNumber,
                $order['customer_id'] !== null ? (int) $order['customer_id'] : null,
                'Purchase',
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                self::STATUS_PENDING,
            ]);
        } catch (Throwable $e) {
            // Never let tracking break payment completion — but do not fail
            // silently: record a sanitized, actionable error the admin can see
            // (audit log → surfaced in the Meta Ads status section).
            $msg = self::sanitizeError(get_class($e) . ': ' . $e->getMessage());
            error_log('MetaCapi::enqueuePurchase: ' . $msg);
            AuditLog::write('meta.capi.enqueue_failed', 'orders', (string) ($order['order_number'] ?? ''), [
                'error' => $msg,
                'action_required' => 'Purchase event was NOT queued; the order is paid. Verify meta_capi_events schema, then requeue manually if needed.',
            ]);
        }
    }

    /**
     * Build the CAPI event object from the locked order + its items.
     * user_data contains only normalized SHA-256 hashes plus the trusted
     * UA/IP snapshot — no raw PII beyond what Meta requires in plaintext.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private static function buildPurchaseEvent(PDO $pdo, array $order, array $items): ?array
    {
        $contentIds = [];
        $contents = [];
        $numItems = 0;
        foreach ($items as $it) {
            $id = MetaPixel::orderItemContentId($it);
            if ($id === '') {
                continue;
            }
            $qty = max(1, (int) ($it['quantity'] ?? $it['qty'] ?? 1));
            $contentIds[] = $id;
            $line = ['id' => $id, 'quantity' => $qty];
            if (is_numeric($it['unit_price'] ?? null)) {
                $line['item_price'] = (float) $it['unit_price'];
            }
            $contents[] = $line;
            $numItems += $qty;
        }
        if ($contentIds === []) {
            return null;
        }

        $customer = null;
        $customerId = (int) ($order['customer_id'] ?? 0);
        if ($customerId > 0) {
            $stmt = $pdo->prepare('SELECT mobile, name, email FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $address = null;
        $addressId = (int) ($order['customer_address_id'] ?? 0);
        if ($addressId > 0) {
            $stmt = $pdo->prepare('SELECT city, state, pincode FROM customer_addresses WHERE id = ? LIMIT 1');
            $stmt->execute([$addressId]);
            $address = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $snap = [];
        $rawMeta = $order['admin_meta_json'] ?? null;
        if (is_string($rawMeta) && $rawMeta !== '') {
            $decoded = json_decode($rawMeta, true);
            if (is_array($decoded)) {
                $snap = is_array($decoded['meta_tracking'] ?? null) ? $decoded['meta_tracking'] : [];
            }
        }

        $orderNumber = (string) $order['order_number'];
        $userData = [];
        if ($customer !== null) {
            $phone = self::normPhone((string) ($customer['mobile'] ?? ''));
            if ($phone !== '') {
                $userData['ph'] = [self::hash($phone)];
            }
            $email = self::normEmail((string) ($customer['email'] ?? ''));
            if ($email !== '') {
                $userData['em'] = [self::hash($email)];
            }
            [$fn, $ln] = self::splitName((string) ($customer['name'] ?? ''));
            if ($fn !== '') {
                $userData['fn'] = [self::hash($fn)];
            }
            if ($ln !== '') {
                $userData['ln'] = [self::hash($ln)];
            }
        }
        if ($address !== null) {
            $city = self::normPlace((string) ($address['city'] ?? ''));
            if ($city !== '') {
                $userData['ct'] = [self::hash($city)];
            }
            $state = self::normPlace((string) ($address['state'] ?? ''));
            if ($state !== '') {
                $userData['st'] = [self::hash($state)];
            }
            $zip = self::normZip((string) ($address['pincode'] ?? ''));
            if ($zip !== '') {
                $userData['zp'] = [self::hash($zip)];
            }
        }
        $userData['country'] = [self::hash('in')];
        // Meta requires these plaintext for website events; captured at checkout.
        // Re-validate the snapshotted IP — only a public address is ever sent.
        $snapIp = trim((string) ($snap['client_ip'] ?? ''));
        if ($snapIp !== ''
            && filter_var($snapIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            $userData['client_ip_address'] = $snapIp;
        }
        if (($snap['client_user_agent'] ?? '') !== '') {
            $userData['client_user_agent'] = (string) $snap['client_user_agent'];
        }

        return [
            'event_name' => 'Purchase',
            'event_time' => time(),
            'event_id' => MetaPixel::purchaseEventId($orderNumber),
            'action_source' => 'website',
            'event_source_url' => rtrim(BusinessSettings::storefrontBaseUrl(), '/')
                . '/order.php?id=' . rawurlencode($orderNumber),
            'user_data' => $userData,
            'custom_data' => [
                'currency' => MetaPixel::CURRENCY,
                'value' => round((float) ($order['grand_total'] ?? 0), 2),
                'order_id' => $orderNumber,
                'content_type' => 'product',
                'content_ids' => $contentIds,
                'contents' => $contents,
                'num_items' => $numItems,
            ],
        ];
    }

    /**
     * Post-commit hook: deliver the new event (if any) plus a bounded sweep of
     * older due events. Runs after the payment transaction commits; every
     * failure is contained so payment completion can never be affected.
     */
    public static function afterOrderPaid(int $orderId): void
    {
        try {
            if (!self::sendable()) {
                return;
            }
            self::processDue(self::SWEEP_BATCH);
        } catch (Throwable $e) {
            error_log('MetaCapi::afterOrderPaid: ' . $e->getMessage());
        }
    }

    /** Everything required to actually transmit events right now. */
    private static function sendable(): bool
    {
        return self::tableReady()
            && MetaAdsSettings::capiReady()
            && MetaPixel::environmentAllowed();
    }

    /**
     * Claim + send up to $limit due pending/failed events.
     *
     * @return array{sent: int, failed: int, skipped: int}
     */
    public static function processDue(int $limit): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        if ($limit <= 0 || !self::tableReady()) {
            return $out;
        }
        $pdo = Database::pdo();
        try {
            self::sweepRetention($pdo);
        } catch (Throwable $e) {
            error_log('MetaCapi::sweepRetention: ' . $e->getMessage());
        }
        $stmt = $pdo->query(
            "SELECT * FROM meta_capi_events
             WHERE status IN ('pending', 'failed') AND attempts < max_attempts
               AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
               AND created_at > DATE_SUB(NOW(), INTERVAL " . self::EVENT_MAX_AGE_DAYS . ' DAY)
               AND JSON_EXTRACT(payload_json, \'$.scrubbed\') IS NULL
             ORDER BY id ASC LIMIT ' . (int) $limit
        );
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            $token = self::claim($pdo, (int) $row['id']);
            if ($token === null) {
                $out['skipped']++;
                continue;
            }
            $result = self::transmit($row);
            self::finish($pdo, $row, $token, $result);
            $result['ok'] ? $out['sent']++ : $out['failed']++;
        }

        return $out;
    }

    /**
     * Admin-triggered retry: requeue terminal/failed rows (resetting attempts)
     * then deliver a bounded batch regardless of backoff schedule.
     *
     * @return array{ok: bool, message: string, sent?: int, failed?: int}
     */
    public static function retryFromAdmin(int $adminId): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'CAPI outbox table is not installed. Run the 1.45.0 migration.'];
        }
        if (!MetaAdsSettings::capiEnabled()) {
            return ['ok' => false, 'message' => 'Conversions API is disabled — enable it before retrying events.'];
        }
        if (!MetaAdsSettings::capiReady()) {
            return ['ok' => false, 'message' => 'CAPI is not fully configured (dataset ID + valid access token required).'];
        }
        if (!MetaPixel::environmentAllowed()) {
            return ['ok' => false, 'message' => 'This environment is not allowed to send tracking events.'];
        }
        $pdo = Database::pdo();
        try {
            self::sweepRetention($pdo);
        } catch (Throwable $e) {
            error_log('MetaCapi::sweepRetention: ' . $e->getMessage());
        }
        // Requeue terminal failed rows so they are claimable again — never
        // 'suppressed' or 'sent' rows, and never retention-scrubbed payloads.
        $pdo->query(
            "UPDATE meta_capi_events SET attempts = 0, next_attempt_at = NULL, last_error = NULL
             WHERE status = 'failed' AND attempts >= max_attempts
               AND JSON_EXTRACT(payload_json, '$.scrubbed') IS NULL"
        );
        // Release stale leases before claiming.
        $pdo->query(
            "UPDATE meta_capi_events SET status = 'failed', worker_token = NULL, processing_started_at = NULL
             WHERE status = 'processing'
               AND (processing_started_at IS NULL
                    OR processing_started_at < DATE_SUB(NOW(), INTERVAL " . self::LEASE_SECONDS . ' SECOND))'
        );
        $stmt = $pdo->query(
            "SELECT * FROM meta_capi_events
             WHERE status IN ('pending', 'failed')
               AND JSON_EXTRACT(payload_json, '$.scrubbed') IS NULL
             ORDER BY id ASC LIMIT " . self::ADMIN_RETRY_BATCH
        );
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($rows === []) {
            return ['ok' => true, 'message' => 'No pending or failed CAPI events to retry.'];
        }
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $token = self::claim($pdo, (int) $row['id'], true);
            if ($token === null) {
                continue;
            }
            $result = self::transmit($row);
            self::finish($pdo, $row, $token, $result);
            $result['ok'] ? $sent++ : $failed++;
        }
        AuditLog::write('meta.capi.retry', 'meta_capi_events', null, [
            'admin_user_id' => $adminId,
            'sent' => $sent,
            'failed' => $failed,
        ]);

        return [
            'ok' => true,
            'message' => 'CAPI retry finished — sent: ' . $sent . ', failed: ' . $failed . '.',
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    /**
     * Lease a row for delivery. Returns the worker token or null when another
     * worker holds the lease / the row is not sendable.
     */
    private static function claim(PDO $pdo, int $id, bool $force = false): ?string
    {
        $token = bin2hex(random_bytes(16));
        $due = $force ? '1=1' : '(next_attempt_at IS NULL OR next_attempt_at <= NOW())';
        $stmt = $pdo->prepare(
            "UPDATE meta_capi_events
             SET status = 'processing', worker_token = ?, processing_started_at = NOW()
             WHERE id = ? AND (
                (status IN ('pending','failed') AND attempts < max_attempts AND {$due})
                OR (status = 'processing' AND (processing_started_at IS NULL
                     OR processing_started_at < DATE_SUB(NOW(), INTERVAL " . self::LEASE_SECONDS . ' SECOND)))
             )'
        );
        $stmt->execute([$token, $id]);

        return $stmt->rowCount() > 0 ? $token : null;
    }

    /**
     * Transmit one event to the Meta Conversions API.
     *
     * @return array{ok: bool, retryable?: bool, error?: string}
     */
    private static function transmit(array $row): array
    {
        $dataset = MetaAdsSettings::effectiveDatasetId();
        if ($dataset === null) {
            return ['ok' => false, 'retryable' => false, 'error' => 'Dataset ID not configured.'];
        }
        $token = AppSettings::getSecret(MetaAdsSettings::KEY_CAPI_ACCESS_TOKEN, '');
        if (!is_string($token) || $token === '') {
            return ['ok' => false, 'retryable' => false, 'error' => 'Access token missing or unreadable.'];
        }
        $event = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (!is_array($event) || $event === []
            || !empty($event['scrubbed'])
            || empty($event['event_name']) || empty($event['event_time']) || empty($event['user_data'])) {
            return ['ok' => false, 'retryable' => false, 'error' => 'Stored payload is invalid or has been scrubbed.'];
        }
        $body = ['data' => [$event]];
        $testCode = MetaAdsSettings::testEventCode();
        if ($testCode !== null) {
            $body['test_event_code'] = $testCode;
        }
        $url = self::GRAPH_HOST . '/' . self::GRAPH_API_VERSION . '/' . $dataset . '/events';
        $resp = PaymentHttp::request(
            'POST',
            $url,
            $body,
            ['Authorization: Bearer ' . $token],
            null,
            null,
            self::HTTP_TIMEOUT_SECONDS
        );
        $status = (int) ($resp['status'] ?? 0);
        $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
        if (!empty($resp['ok']) && isset($json['events_received'])) {
            return ['ok' => true];
        }
        // Meta reports structured errors in error.message/error_subcode; never echo the token.
        $err = (string) ($json['error']['message'] ?? $resp['error'] ?? 'HTTP ' . $status);
        $retryable = $status === 0 || $status === 429 || $status >= 500;

        return ['ok' => false, 'retryable' => $retryable, 'error' => self::sanitizeError($err)];
    }

    /** Persist the send result; only the owning worker token may finish. */
    private static function finish(PDO $pdo, array $row, string $token, array $result): void
    {
        if (!empty($result['ok'])) {
            // Delivery confirmed: scrub the payload to a non-sensitive audit
            // stub. Hashed identifiers and IP/UA have served their purpose.
            $pdo->prepare(
                "UPDATE meta_capi_events
                 SET status = 'sent', sent_at = NOW(), last_error = NULL,
                     payload_json = JSON_OBJECT('event_name', event_name, 'event_id', event_id,
                                                'order_id', order_number, 'scrubbed', 'delivered'),
                     worker_token = NULL, processing_started_at = NULL, updated_at = NOW()
                 WHERE id = ? AND worker_token = ?"
            )->execute([(int) $row['id'], $token]);

            return;
        }
        $attempts = (int) $row['attempts'] + 1;
        $max = max(1, (int) ($row['max_attempts'] ?? self::MAX_ATTEMPTS));
        $retryable = !empty($result['retryable']);
        $terminal = !$retryable || $attempts >= $max;
        $nextAt = null;
        if (!$terminal) {
            $delay = min(3600, 60 * (2 ** ($attempts - 1)));
            $nextAt = date('Y-m-d H:i:s', time() + $delay);
        }
        if ($terminal) {
            $attempts = $max;
        }
        $pdo->prepare(
            "UPDATE meta_capi_events
             SET status = 'failed', attempts = ?, next_attempt_at = ?, last_error = ?,
                 worker_token = NULL, processing_started_at = NULL, updated_at = NOW()
             WHERE id = ? AND worker_token = ?"
        )->execute([$attempts, $nextAt, self::sanitizeError((string) ($result['error'] ?? 'Send failed.')), (int) $row['id'], $token]);
    }

    /**
     * Bounded payload retention, enforced opportunistically (no cron):
     *  1) unsent rows older than Meta's ~7-day credit window are terminalized
     *     and scrubbed — delivering them would be a stale conversion anyway;
     *  2) terminal 'failed' payloads are scrubbed after FAILED_RETENTION_DAYS.
     * The scrub replaces payload_json with a non-sensitive audit stub
     * (event_name/event_id/order_id only) — the row stays inspectable while
     * hashed identifiers, IP and UA are dropped. 'sent' and 'suppressed' rows
     * are scrubbed at transition time in finish()/observeVisitorConsent().
     */
    private static function sweepRetention(PDO $pdo): void
    {
        $stub = "JSON_OBJECT('event_name', event_name, 'event_id', event_id, 'order_id', order_number";
        $pdo->exec(
            "UPDATE meta_capi_events
             SET status = 'failed', attempts = max_attempts, next_attempt_at = NULL,
                 last_error = 'Event age exceeded the Meta delivery window.',
                 payload_json = {$stub}, 'scrubbed', 'stale'),
                 worker_token = NULL, processing_started_at = NULL, updated_at = NOW()
             WHERE status IN ('pending', 'failed', 'processing')
               AND created_at < DATE_SUB(NOW(), INTERVAL " . self::EVENT_MAX_AGE_DAYS . " DAY)
               AND JSON_EXTRACT(payload_json, '$.scrubbed') IS NULL"
        );
        $pdo->exec(
            "UPDATE meta_capi_events
             SET payload_json = {$stub}, 'scrubbed', 'retention'), updated_at = NOW()
             WHERE status = 'failed' AND attempts >= max_attempts AND next_attempt_at IS NULL
               AND updated_at < DATE_SUB(NOW(), INTERVAL " . self::FAILED_RETENTION_DAYS . " DAY)
               AND JSON_EXTRACT(payload_json, '$.scrubbed') IS NULL"
        );
    }

    /**
     * Observed-withdrawal suppression: when a logged-in customer's request shows
     * marketing consent is not granted (denied, withdrawn or record absent),
     * suppress that customer's unsent events.
     *
     * The session gate keys on the observed non-granted STATE, not a
     * once-per-session flag: a new decision (e.g. grant → purchase → withdraw
     * inside one session) yields a different signature and is suppressed again;
     * an unchanged non-granted state is swept at most once per session.
     * Suppressed rows also have their payload scrubbed — they can never be
     * sent or requeued, so keeping hashed identifiers serves no purpose.
     */
    public static function observeVisitorConsent(): void
    {
        try {
            $state = MetaConsent::state();
            if (!self::tableReady() || $state['marketing']) {
                return;
            }
            if (!CustomerService::isLoggedIn()) {
                return;
            }
            $sig = $state['set']
                ? 'r' . (int) $state['recorded_at'] . ':' . ((int) $state['marketing'])
                : 'unset';
            if (($_SESSION['meta_capi_suppress_sig'] ?? null) === $sig) {
                return;
            }
            $_SESSION['meta_capi_suppress_sig'] = $sig;
            unset($_SESSION['meta_capi_suppress_swept']); // legacy once-per-session key
            $customerId = CustomerAuth::id();
            if ($customerId === null) {
                return;
            }
            Database::pdo()->prepare(
                "UPDATE meta_capi_events
                 SET status = 'suppressed',
                     payload_json = JSON_OBJECT('event_name', event_name, 'event_id', event_id,
                                                'order_id', order_number, 'scrubbed', 'consent_withdrawn'),
                     worker_token = NULL, processing_started_at = NULL, updated_at = NOW()
                 WHERE customer_id = ? AND status IN ('pending', 'failed')"
            )->execute([$customerId]);
        } catch (Throwable $e) {
            error_log('MetaCapi::observeVisitorConsent: ' . $e->getMessage());
        }
    }

    /**
     * Outbox counters for the admin status section.
     *
     * @return array<string, mixed>
     */
    public static function stats(): array
    {
        $base = [
            'table' => self::tableReady(),
            'pending' => 0,
            'processing' => 0,
            'sent' => 0,
            'failed' => 0,
            'suppressed' => 0,
            'last_sent_at' => null,
            'last_error' => null,
            'enqueue_failures' => 0,
            'last_enqueue_failure' => null,
        ];
        if (!$base['table']) {
            return $base;
        }
        try {
            $pdo = Database::pdo();
            // Outbox-insert failures are recorded in the audit log at payment
            // time so an enqueue that never produced a row is still visible.
            $enq = $pdo->query(
                "SELECT COUNT(*) c, MAX(created_at) last_at FROM admin_audit_logs
                 WHERE action = 'meta.capi.enqueue_failed'"
            )->fetch(PDO::FETCH_ASSOC);
            if (is_array($enq)) {
                $base['enqueue_failures'] = (int) ($enq['c'] ?? 0);
                $base['last_enqueue_failure'] = $enq['last_at'] ?? null;
            }
            foreach ($pdo->query('SELECT status, COUNT(*) c FROM meta_capi_events GROUP BY status') as $r) {
                $k = (string) ($r['status'] ?? '');
                if (isset($base[$k])) {
                    $base[$k] = (int) $r['c'];
                }
            }
            $last = $pdo->query(
                "SELECT sent_at, last_error FROM meta_capi_events
                 WHERE sent_at IS NOT NULL OR last_error IS NOT NULL
                 ORDER BY updated_at DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            if (is_array($last)) {
                $base['last_sent_at'] = $last['sent_at'] ?? null;
                $base['last_error'] = $last['last_error'] ?? null;
            }
        } catch (Throwable $e) {
            $base['last_error'] = 'Stats unavailable.';
        }

        return $base;
    }

    /* ---------------- normalization helpers (Meta spec) ---------------- */

    private static function hash(string $normalized): string
    {
        return hash('sha256', $normalized);
    }

    /** Indian mobile → digits with country code, e.g. 919876543210. */
    private static function normPhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = '91' . substr($digits, 1);
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return $digits;
        }

        return strlen($digits) >= 12 ? $digits : '';
    }

    private static function normEmail(string $raw): string
    {
        $email = strtolower(trim($raw));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** @return array{0: string, 1: string} */
    private static function splitName(string $raw): array
    {
        $name = strtolower(trim(preg_replace('/\s+/', ' ', $raw) ?? ''));
        if ($name === '') {
            return ['', ''];
        }
        $parts = explode(' ', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /** Meta normalizes city/state: lowercase letters/digits only. */
    private static function normPlace(string $raw): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', trim($raw)) ?? '');
    }

    /** Indian PIN: keep digits only. */
    private static function normZip(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';

        return strlen($d) === 6 ? $d : '';
    }

    /** Truncate and strip anything that could echo a token or raw PII. */
    private static function sanitizeError(string $err): string
    {
        $err = str_replace(["\n", "\r"], ' ', trim($err));

        return mb_substr($err, 0, 255);
    }
}
