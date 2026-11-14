<?php
// CDP evaluate: php _dbg_cdp_eval.php <url> <width> <js-expression>
[$url, $w, $expr] = [$argv[1], (int)$argv[2], $argv[3]];
$json = json_decode(file_get_contents('http://127.0.0.1:9222/json'), true);
$page = null; foreach ($json as $t) { if (($t['type'] ?? '') === 'page') { $page = $t; break; } }
$ws = parse_url($page['webSocketDebuggerUrl']);
$sock = fsockopen($ws['host'], $ws['port'], $e, $es, 5);
$key = base64_encode(random_bytes(16));
fwrite($sock, "GET {$ws['path']} HTTP/1.1\r\nHost: {$ws['host']}:{$ws['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
$resp=''; while (!str_contains($resp,"\r\n\r\n")) $resp .= fread($sock,1024);
stream_set_timeout($sock, 15);
function ws_send($s,$d){$l=strlen($d);$m=random_bytes(4);
 if($l<126)$h=chr(0x81).chr(0x80|$l);elseif($l<65536)$h=chr(0x81).chr(0xFE).pack('n',$l);else $h=chr(0x81).chr(0xFF).pack('J',$l);
 $x='';for($i=0;$i<$l;$i++)$x.=$d[$i]^$m[$i%4];fwrite($s,$h.$m.$x);}
function ws_recv($s){$msg='';while(true){$b=fread($s,2);if(strlen($b)<2)return null;
 $u=unpack('C*',$b);$fin=$u[1]&0x80;$len=$u[2]&0x7F;
 if($len===126)$len=unpack('n',fread($s,2))[1];elseif($len===127)$len=unpack('J',fread($s,8))[1];
 if($u[2]&0x80){$m=fread($s,4);$p=fread($s,$len);for($i=0;$i<$len;$i++)$p[$i]^=$m[$i%4];}
 else $p=$len?fread($s,$len):'';
 while(strlen($p)<$len)$p.=fread($s,$len-strlen($p));$msg.=$p;if($fin)return $msg;}}
function cdp($s,$id,$m,$p=[]){ws_send($s,json_encode(['id'=>$id,'method'=>$m,'params'=>$p]));
 while(true){$r=json_decode(ws_recv($s),true);if(!$r)return null;if(($r['id']??null)===$id)return $r['result']??$r;}}
cdp($sock,1,'Page.enable');
cdp($sock,2,'Emulation.setDeviceMetricsOverride',['width'=>$w,'height'=>1200,'deviceScaleFactor'=>1,'mobile'=>$w<768]);
cdp($sock,3,'Page.navigate',['url'=>$url]);
usleep(4000000);
$r = cdp($sock,4,'Runtime.evaluate',['expression'=>$expr,'returnByValue'=>true,'awaitPromise'=>true]);
echo json_encode($r['result']['value'] ?? $r, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
