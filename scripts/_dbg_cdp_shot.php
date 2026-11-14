<?php
// Minimal CDP screenshot driver: exact viewport widths via Emulation.setDeviceMetricsOverride.
// Usage: php _dbg_cdp_shot.php <url> <width> <height> <out.png>

[$url, $w, $h, $out] = [$argv[1], (int)$argv[2], (int)$argv[3], $argv[4]];

$json = json_decode(file_get_contents('http://127.0.0.1:9222/json'), true);
$page = null;
foreach ($json as $t) { if (($t['type'] ?? '') === 'page') { $page = $t; break; } }
if (!$page) { fwrite(STDERR, "no page target\n"); exit(1); }
$ws = parse_url($page['webSocketDebuggerUrl']);
$sock = fsockopen($ws['host'], $ws['port'], $e, $es, 5);
if (!$sock) { fwrite(STDERR, "ws fail $es\n"); exit(1); }
$key = base64_encode(random_bytes(16));
$req = "GET {$ws['path']} HTTP/1.1\r\nHost: {$ws['host']}:{$ws['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n";
fwrite($sock, $req);
$resp = '';
while (!str_contains($resp, "\r\n\r\n")) { $resp .= fread($sock, 1024); }
if (!str_contains($resp, '101')) { fwrite(STDERR, "handshake fail\n$resp\n"); exit(1); }

function ws_send($s, $data) {
    $len = strlen($data); $mask = random_bytes(4);
    if ($len < 126) $hdr = chr(0x81) . chr(0x80 | $len);
    elseif ($len < 65536) $hdr = chr(0x81) . chr(0x80 | 126) . pack('n', $len);
    else $hdr = chr(0x81) . chr(0x80 | 127) . pack('J', $len);
    $masked = '';
    for ($i = 0; $i < $len; $i++) $masked .= $data[$i] ^ $mask[$i % 4];
    fwrite($s, $hdr . $mask . $masked);
}
function ws_recv($s) {
    $msg = '';
    while (true) {
        $b = fread($s, 2); if (strlen($b) < 2) return null;
        [$o, $l] = array_values(unpack('C*', $b));
        $fin = $o & 0x80; $len = $l & 0x7F;
        if ($len === 126) $len = unpack('n', fread($s, 2))[1];
        elseif ($len === 127) $len = unpack('J', fread($s, 8))[1];
        if ($l & 0x80) { $m = fread($s, 4); $p = fread($s, $len); for ($i=0;$i<$len;$i++) $p[$i] ^= $m[$i%4]; }
        else $p = $len ? fread($s, $len) : '';
        while (strlen($p) < $len) $p .= fread($s, $len - strlen($p));
        $msg .= $p;
        if ($fin) return $msg;
    }
}
function cdp($s, $id, $method, $params = []) {
    ws_send($s, json_encode(['id' => $id, 'method' => $method, 'params' => $params]));
    while (true) {
        $m = json_decode(ws_recv($s), true);
        if (!$m) return null;
        if (($m['id'] ?? null) === $id) return $m['result'] ?? $m;
    }
}

cdp($sock, 1, 'Page.enable');
cdp($sock, 2, 'Emulation.setDeviceMetricsOverride', ['width' => $w, 'height' => $h, 'deviceScaleFactor' => 1, 'mobile' => $w < 768]);
cdp($sock, 3, 'Page.navigate', ['url' => $url]);
usleep(3500000); // let Tailwind CDN + fonts settle
$r = cdp($sock, 4, 'Page.captureScreenshot', ['format' => 'png']);
if (!empty($r['data'])) { file_put_contents($out, base64_decode($r['data'])); echo "OK $out\n"; }
else { fwrite(STDERR, "shot fail\n"); }
