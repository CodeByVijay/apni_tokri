<?php

declare(strict_types=1);

/**
 * True parallel Media Library upload + purge/attach races (disposable DB only).
 *
 * Run: php scripts/run-in-disposable-db.php --migrate-media-library scripts/test-media-library-concurrent.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';
require_once __DIR__ . '/includes/media_library_concurrent_helpers.php';

$fail = 0;
const ML_PARALLEL_ROUNDS = 10;

function okC(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

if (!MediaLibrary::isEnabled()) {
    echo "SKIP: media_assets table not present — use --migrate-media-library on disposable runner\n";
    exit(0);
}

TestScriptSafety::assertDisposableDatabaseOrAbort();
TestScriptSafety::assertSafeToMutateSettings();
Install::ensureConnection();
$pdo = Database::pdo();
$tok = strtolower(bin2hex(random_bytes(4)));
$child = __DIR__ . '/test-media-library-concurrent-child.php';

if (!function_exists('proc_open')) {
    echo "SKIP: proc_open unavailable — parallel concurrency not executed\n";
    exit(2);
}

Upload::ensureUploadsRootSecured();

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$parentEnv = getenv();
$baseEnv = is_array($parentEnv) ? $parentEnv : [];
$baseEnv['APNI_TOKRI_DISPOSABLE_DB'] = '1';
$dbCfg = App::config()['database'];
$baseEnv['APNI_TOKRI_TEST_DB_NAME'] = (string) ($dbCfg['name'] ?? getenv('APNI_TOKRI_TEST_DB_NAME') ?: '');
$baseEnv['APNI_TOKRI_ORIGINAL_DB_NAME'] = (string) (getenv('APNI_TOKRI_ORIGINAL_DB_NAME') ?: '');
$baseEnv['APNI_TOKRI_TEST_DB_HOST'] = (string) ($dbCfg['host'] ?? '127.0.0.1');
$baseEnv['APNI_TOKRI_TEST_DB_PORT'] = (string) ($dbCfg['port'] ?? 3306);
$baseEnv['APNI_TOKRI_TEST_DB_USER'] = (string) ($dbCfg['user'] ?? 'root');
$baseEnv['APNI_TOKRI_TEST_DB_PASS'] = (string) ($dbCfg['password'] ?? $dbCfg['pass'] ?? '');

/**
 * @return array{payA: array<string, mixed>, payB: array<string, mixed>, sha: string, winnerId: int}
 */
function ml_run_parallel_ingest_pair(
    string $child,
    array $baseEnv,
    array $descriptors,
    string $tok,
    int $round
): array {
    $suffix = $tok . '-r' . $round;
    $pngBody = (base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true
    ) ?: '') . $suffix;
    $shaExpected = hash('sha256', $pngBody);
    $imgA = sys_get_temp_dir() . '/ml-par-a-' . $suffix . '.png';
    $imgB = sys_get_temp_dir() . '/ml-par-b-' . $suffix . '.png';
    file_put_contents($imgA, $pngBody);
    file_put_contents($imgB, $pngBody);

    $barrierFp = media_library_concurrent_barrier_open();
    $barrierPath = media_library_concurrent_barrier_path($barrierFp);
    $ingestBase = array_merge($baseEnv, [
        'AT_ML_CONCURRENT_CHILD' => '1',
        'AT_ML_CONCURRENT_MODE' => 'ingest',
        'AT_ML_BARRIER' => $barrierPath,
    ]);
    $outA = tempnam(sys_get_temp_dir(), 'atmla');
    $outB = tempnam(sys_get_temp_dir(), 'atmlb');
    $envA = array_merge($ingestBase, ['AT_ML_CONCURRENT_OUT' => $outA, 'AT_ML_IMAGE_PATH' => $imgA]);
    $envB = array_merge($ingestBase, ['AT_ML_CONCURRENT_OUT' => $outB, 'AT_ML_IMAGE_PATH' => $imgB]);

    $procA = proc_open([PHP_BINARY, $child], $descriptors, $pipesA, null, $envA);
    $procB = proc_open([PHP_BINARY, $child], $descriptors, $pipesB, null, $envB);
    if (!is_resource($procA) || !is_resource($procB)) {
        media_library_concurrent_barrier_release($barrierFp);
        throw new RuntimeException('proc_open failed for round ' . $round);
    }
    foreach ($pipesA as $p) {
        is_resource($p) && fclose($p);
    }
    foreach ($pipesB as $p) {
        is_resource($p) && fclose($p);
    }
    media_library_concurrent_barrier_release($barrierFp);
    proc_close($procA);
    proc_close($procB);
    $payA = json_decode((string) file_get_contents($outA), true);
    $payB = json_decode((string) file_get_contents($outB), true);
    @unlink($outA);
    @unlink($outB);
    @unlink($imgA);
    @unlink($imgB);

    return [
        'payA' => is_array($payA) ? $payA : [],
        'payB' => is_array($payB) ? $payB : [],
        'sha' => $shaExpected,
        'body' => $pngBody,
    ];
}

$pdo = Database::pdo();
$stmtActive = $pdo->prepare(
    'SELECT COUNT(*) FROM media_assets WHERE file_sha256 = ? AND deleted_at IS NULL'
);
$stmtRow = $pdo->prepare(
    'SELECT id, file_path FROM media_assets WHERE file_sha256 = ? AND deleted_at IS NULL LIMIT 1'
);

for ($round = 1; $round <= ML_PARALLEL_ROUNDS; $round++) {
    echo "PARALLEL ROUND {$round}/" . ML_PARALLEL_ROUNDS . ": duplicate ingest workers…\n";
    try {
        $pair = ml_run_parallel_ingest_pair($child, $baseEnv, $descriptors, $tok, $round);
    } catch (Throwable $e) {
        okC(false, "round {$round}: " . $e->getMessage());
        continue;
    }
    $payA = $pair['payA'];
    $payB = $pair['payB'];
    $shaExpected = $pair['sha'];
    if (!empty($payA['message']) || !empty($payB['message'])) {
        echo "  messages: A=" . ($payA['message'] ?? '') . ' B=' . ($payB['message'] ?? '') . "\n";
    }
    okC(!empty($payA['ok']) && !empty($payB['ok']), "round {$round}: both workers ok");
    $midA = (int) ($payA['media_id'] ?? 0);
    $midB = (int) ($payB['media_id'] ?? 0);
    okC($midA > 0 && $midB > 0, "round {$round}: both media_id");
    okC($midA === $midB, "round {$round}: same media_id");
    $stmtActive->execute([$shaExpected]);
    okC((int) $stmtActive->fetchColumn() === 1, "round {$round}: one active row");
    $stmtRow->execute([$shaExpected]);
    $row = $stmtRow->fetch(PDO::FETCH_ASSOC);
    $winnerPath = is_array($row) ? (string) ($row['file_path'] ?? '') : '';
    $winnerId = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    $winnerFull = $winnerPath !== '' ? APNI_TOKRI_ROOT . '/' . ltrim($winnerPath, '/') : '';
    okC($winnerId === $midA, "round {$round}: row id matches workers");
    okC($winnerFull !== '' && is_file($winnerFull), "round {$round}: one physical file");
    okC(
        $winnerFull !== '' && is_file($winnerFull) && hash_file('sha256', $winnerFull) === $shaExpected,
        "round {$round}: file SHA matches"
    );
    $loserOrphans = 0;
    $winnerNorm = $winnerFull !== '' ? str_replace('\\', '/', $winnerFull) : '';
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(APNI_TOKRI_ROOT . '/uploads/media', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $full = str_replace('\\', '/', $file->getPathname());
        if (hash_file('sha256', $full) !== $shaExpected) {
            continue;
        }
        if ($winnerNorm !== '' && $full !== $winnerNorm) {
            $loserOrphans++;
        }
    }
    okC($loserOrphans === 0, "round {$round}: no orphan loser files on disk");
    if ($winnerId > 0) {
        $pdo->prepare('DELETE FROM product_images WHERE media_id = ?')->execute([$winnerId]);
        $pdo->prepare('DELETE FROM media_assets WHERE id = ?')->execute([$winnerId]);
    }
}

echo "SEQUENTIAL: duplicate ingest after parallel rounds…\n";
$seqBody = (base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true
) ?: '') . $tok . '-seq';
$seqPath = sys_get_temp_dir() . '/ml-seq-' . $tok . '.png';
file_put_contents($seqPath, $seqBody);
$seqSha = hash('sha256', $seqBody);
$seqFile = [
    'name' => 'ml-seq-' . $tok . '.png',
    'type' => 'image/png',
    'tmp_name' => $seqPath,
    'error' => UPLOAD_ERR_OK,
    'size' => (int) filesize($seqPath),
];
$seq1 = MediaLibrary::ingestUpload($seqFile, 1);
$seq2 = MediaLibrary::ingestUpload($seqFile, 1);
okC(!empty($seq1['ok']) && !empty($seq2['ok']), 'sequential duplicate ingests ok');
okC((int) ($seq1['media_id'] ?? 0) === (int) ($seq2['media_id'] ?? 0), 'sequential duplicate same media_id');
$stmtActive->execute([$seqSha]);
okC((int) $stmtActive->fetchColumn() === 1, 'sequential duplicate one row');
$stmtRow->execute([$seqSha]);
$seqRow = $stmtRow->fetch(PDO::FETCH_ASSOC);
if (is_array($seqRow)) {
    $pdo->prepare('DELETE FROM media_assets WHERE id = ?')->execute([(int) $seqRow['id']]);
}
@unlink($seqPath);

$pngPrefix = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true
) ?: '';

echo "PARALLEL: purge vs attach on soft-deleted media…\n";
$raceBody = $pngPrefix . $tok . '-soft-race';
$raceSha = hash('sha256', $raceBody);
$racePath = sys_get_temp_dir() . '/ml-race-' . $tok . '.png';
file_put_contents($racePath, $raceBody);
$raceIngest = MediaLibrary::ingestUpload([
    'name' => 'ml-race-' . $tok . '.png',
    'type' => 'image/png',
    'tmp_name' => $racePath,
    'error' => UPLOAD_ERR_OK,
    'size' => (int) filesize($racePath),
], 1);
$raceMid = (int) ($raceIngest['media_id'] ?? 0);
okC($raceMid > 0, 'soft-race fixture ingested');
$pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)')->execute(['ML Race', 'ml-race-' . $tok]);
$catRace = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO products (category_id, name, slug, sku, status) VALUES (?, ?, ?, ?, ?)'
)->execute([$catRace, 'ML Race', 'ml-race-prod-' . $tok, 'MLR-' . $tok, 'draft']);
$racePid = (int) $pdo->lastInsertId();
MediaLibrary::softDelete($raceMid);
ml_spawn_purge_attach_race($child, $baseEnv, $descriptors, $raceMid, $racePid, true);

echo "PARALLEL: purge vs attach on active (non-deleted) media…\n";
$liveBody = $pngPrefix . $tok . '-live-race';
$livePath = sys_get_temp_dir() . '/ml-live-' . $tok . '.png';
file_put_contents($livePath, $liveBody);
$liveIngest = MediaLibrary::ingestUpload([
    'name' => 'ml-live-' . $tok . '.png',
    'type' => 'image/png',
    'tmp_name' => $livePath,
    'error' => UPLOAD_ERR_OK,
    'size' => (int) filesize($livePath),
], 1);
$liveMid = (int) ($liveIngest['media_id'] ?? 0);
$livePid = $racePid;
okC($liveMid > 0, 'live-race fixture ingested');
ml_spawn_purge_attach_race($child, $baseEnv, $descriptors, $liveMid, $livePid, false);
okC(MediaLibrary::productUsageCount($liveMid) >= 1, 'live-race: attach kept media linked after race');
$pdo->prepare('DELETE FROM product_images WHERE media_id = ?')->execute([$liveMid]);
$pdo->prepare('DELETE FROM media_assets WHERE id = ?')->execute([$liveMid]);

$pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$racePid]);
$pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$racePid]);
$pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$catRace]);
@unlink($racePath);
@unlink($livePath);

echo "\nSUMMARY fail={$fail} (parallel + sequential concurrency)\n";
exit($fail > 0 ? 1 : 0);

/**
 * @param array<int, mixed> $descriptors
 */
function ml_spawn_purge_attach_race(
    string $child,
    array $baseEnv,
    array $descriptors,
    int $mediaId,
    int $productId,
    bool $expectPurgeWin
): void {
    global $fail;
    $barrier2 = media_library_concurrent_barrier_open();
    $bar2 = media_library_concurrent_barrier_path($barrier2);
    $outP = tempnam(sys_get_temp_dir(), 'atmlP');
    $outT = tempnam(sys_get_temp_dir(), 'atmlT');
    $envP = array_merge($baseEnv, [
        'AT_ML_CONCURRENT_CHILD' => '1',
        'AT_ML_CONCURRENT_OUT' => $outP,
        'AT_ML_CONCURRENT_MODE' => 'purge',
        'AT_ML_MEDIA_ID' => (string) $mediaId,
        'AT_ML_BARRIER' => $bar2,
    ]);
    $envT = array_merge($baseEnv, [
        'AT_ML_CONCURRENT_CHILD' => '1',
        'AT_ML_CONCURRENT_OUT' => $outT,
        'AT_ML_CONCURRENT_MODE' => 'attach',
        'AT_ML_MEDIA_ID' => (string) $mediaId,
        'AT_ML_PRODUCT_ID' => (string) $productId,
        'AT_ML_BARRIER' => $bar2,
    ]);
    $procP = proc_open([PHP_BINARY, $child], $descriptors, $pipesP, null, $envP);
    $procT = proc_open([PHP_BINARY, $child], $descriptors, $pipesT, null, $envT);
    if (!is_resource($procP) || !is_resource($procT)) {
        media_library_concurrent_barrier_release($barrier2);
        okC(false, 'proc_open purge/attach race');
        return;
    }
    foreach ($pipesP as $p) {
        is_resource($p) && fclose($p);
    }
    foreach ($pipesT as $p) {
        is_resource($p) && fclose($p);
    }
    media_library_concurrent_barrier_release($barrier2);
    proc_close($procP);
    proc_close($procT);
    $pPurge = json_decode((string) file_get_contents($outP), true);
    $pAttach = json_decode((string) file_get_contents($outT), true);
    @unlink($outP);
    @unlink($outT);
    $purgeWon = is_array($pPurge) && !empty($pPurge['ok']);
    $attachWon = is_array($pAttach) && !empty($pAttach['ok']);
    if ($expectPurgeWin) {
        okC($purgeWon, 'soft-deleted race: purge ok');
        okC(!$attachWon, 'soft-deleted race: attach rejected');
    } else {
        okC(!$purgeWon, 'active race: purge rejected (not soft-deleted or linked)');
        okC($attachWon, 'active race: attach ok');
    }
}
