<?php
declare(strict_types=1);

// Iron Hills — small authoritative room service for the browser artillery game.
// Rooms are JSON snapshots in SQLite. Every state-changing action is performed
// inside BEGIN IMMEDIATE so simultaneous shots cannot both consume one turn.

session_start();
header_remove('X-Powered-By');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

const DB_FILE = __DIR__ . '/tanks_rooms.sqlite';
const JSON_DB_FILE = __DIR__ . '/tanks_rooms.json';
const MAX_PLAYERS = 10;
const PLAYER_TIMEOUT = 25;
const EMPTY_ROOM_TIMEOUT = 1800;
const FIELD_W = 1280;
const FIELD_H = 720;
const TERRAIN_STEP = 4;
const TERRAIN_POINTS = 321;
const TANK_GROUND_OFFSET = 5;
const TANK_PAD_HALF = 18;
const TANK_PAD_FEATHER = 8;

const COLORS = ['#f4d35e','#ee6352','#59cd90','#3fa7d6','#b388eb','#ff9f1c','#f15bb5','#a8dadc','#ef476f','#06d6a0'];

function jsonOut(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function db(): SQLite3 {
    static $db = null;
    if ($db instanceof SQLite3) return $db;
    if (!class_exists('SQLite3')) throw new RuntimeException('SQLite storage is unavailable.');
    $db = new SQLite3(DB_FILE);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');
    $db->exec('CREATE TABLE IF NOT EXISTS rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        state TEXT NOT NULL,
        private INTEGER NOT NULL DEFAULT 0,
        max_players INTEGER NOT NULL DEFAULT 4,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
    )');
    return $db;
}

function usingSqlite(): bool { return class_exists('SQLite3'); }

function readJsonStore($handle = null): array {
    if ($handle) {
        rewind($handle);
        $raw = stream_get_contents($handle);
    } else {
        $raw = is_file(JSON_DB_FILE) ? (string)file_get_contents(JSON_DB_FILE) : '';
    }
    $store = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($store) ? $store : ['rooms'=>[]];
}

function writeJsonStore($handle, array $store): void {
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($store, JSON_UNESCAPED_SLASHES));
    fflush($handle);
}

function token(): string {
    if (empty($_SESSION['tanks_player_id'])) $_SESSION['tanks_player_id'] = bin2hex(random_bytes(16));
    return (string)$_SESSION['tanks_player_id'];
}

function cleanText(string $value, int $max, string $fallback): string {
    $value = trim(preg_replace('/[^\p{L}\p{N} _.,!?\'"()\-]/u', '', $value) ?? '');
    $value = function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    return $value !== '' ? $value : $fallback;
}

function cleanCode(string $code): string {
    return strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) ?? '', 0, 6));
}

function generateCode(): string {
    do {
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        if (usingSqlite()) {
            $stmt = db()->prepare('SELECT 1 FROM rooms WHERE code=:code');
            $stmt->bindValue(':code', $code, SQLITE3_TEXT);
            $exists = (bool)$stmt->execute()->fetchArray(SQLITE3_NUM);
        } else {
            $exists = getRoom($code) !== null;
        }
    } while ($exists);
    return $code;
}

function getRoom(string $code): ?array {
    if (!usingSqlite()) {
        $store = readJsonStore();
        return isset($store['rooms'][$code]) && is_array($store['rooms'][$code]) ? $store['rooms'][$code] : null;
    }
    $stmt = db()->prepare('SELECT * FROM rooms WHERE code=:code LIMIT 1');
    $stmt->bindValue(':code', $code, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    $row['state'] = json_decode((string)$row['state'], true) ?: [];
    return $row;
}

function deleteRoom(string $code): void {
    if (!usingSqlite()) {
        $handle = fopen(JSON_DB_FILE, 'c+');
        if (!$handle) return;
        flock($handle, LOCK_EX);
        $store = readJsonStore($handle);
        unset($store['rooms'][$code]);
        writeJsonStore($handle, $store);
        flock($handle, LOCK_UN);
        fclose($handle);
        return;
    }
    $stmt = db()->prepare('DELETE FROM rooms WHERE code=:code');
    $stmt->bindValue(':code', $code, SQLITE3_TEXT);
    $stmt->execute();
}

function mutateRoom(string $code, callable $callback): array {
    if (!usingSqlite()) {
        $handle = fopen(JSON_DB_FILE, 'c+');
        if (!$handle) return ['room'=>null,'result'=>null];
        flock($handle, LOCK_EX);
        $store = readJsonStore($handle);
        if (!isset($store['rooms'][$code])) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return ['room'=>null,'result'=>null];
        }
        $room = $store['rooms'][$code];
        try {
            $result = $callback($room);
            if (empty($result['noSave'])) {
                $room['updated_at'] = time();
                $store['rooms'][$code] = $room;
                writeJsonStore($handle, $store);
            }
            flock($handle, LOCK_UN);
            fclose($handle);
            return ['room'=>$room,'result'=>$result];
        } catch (Throwable $e) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw $e;
        }
    }
    $db = db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $room = getRoom($code);
        if (!$room) {
            $db->exec('ROLLBACK');
            return ['room'=>null,'result'=>null];
        }
        $result = $callback($room);
        if (empty($result['noSave'])) {
            $now = time();
            $stmt = $db->prepare('UPDATE rooms SET state=:state,updated_at=:updated WHERE code=:code');
            $stmt->bindValue(':state', json_encode($room['state'], JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
            $stmt->bindValue(':updated', $now, SQLITE3_INTEGER);
            $stmt->bindValue(':code', $code, SQLITE3_TEXT);
            $stmt->execute();
            $room['updated_at'] = $now;
        }
        $db->exec('COMMIT');
        return ['room'=>$room,'result'=>$result];
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function insertRoom(array $room): void {
    if (!usingSqlite()) {
        $handle = fopen(JSON_DB_FILE, 'c+');
        if (!$handle) jsonOut(['ok'=>false,'error'=>'Room storage is not writable.'], 500);
        flock($handle, LOCK_EX);
        $store = readJsonStore($handle);
        $store['rooms'][$room['code']] = $room;
        writeJsonStore($handle, $store);
        flock($handle, LOCK_UN);
        fclose($handle);
        return;
    }
    $stmt=db()->prepare('INSERT INTO rooms(code,name,state,private,max_players,created_at,updated_at) VALUES(:code,:name,:state,:private,:max,:now,:now)');
    $stmt->bindValue(':code',$room['code'],SQLITE3_TEXT); $stmt->bindValue(':name',$room['name'],SQLITE3_TEXT);
    $stmt->bindValue(':state',json_encode($room['state'],JSON_UNESCAPED_SLASHES),SQLITE3_TEXT);
    $stmt->bindValue(':private',$room['private'],SQLITE3_INTEGER); $stmt->bindValue(':max',$room['max_players'],SQLITE3_INTEGER);
    $stmt->bindValue(':now',$room['created_at'],SQLITE3_INTEGER); $stmt->execute();
}

function storedRooms(): array {
    if (!usingSqlite()) return array_values(readJsonStore()['rooms'] ?? []);
    $rooms=[];
    $res=db()->query('SELECT * FROM rooms ORDER BY updated_at DESC');
    while ($row=$res->fetchArray(SQLITE3_ASSOC)) {
        $row['state']=json_decode((string)$row['state'],true)?:[];
        $rooms[]=$row;
    }
    return $rooms;
}

function settingsFrom(array $src): array {
    $terrain = (string)($src['terrain'] ?? 'rolling');
    if (!in_array($terrain, ['rolling','mountains','valleys','random'], true)) $terrain = 'rolling';
    $teamMode = (string)($src['teamMode'] ?? 'ffa');
    if (!in_array($teamMode, ['ffa','humans_cpu','alternating'], true)) $teamMode = 'ffa';
    $walls = (string)($src['walls'] ?? 'open');
    if (!in_array($walls, ['open','wrap','bounce'], true)) $walls = 'open';
    $gravity = (int)($src['gravity'] ?? 100);
    if (!in_array($gravity, [70,100,130], true)) $gravity = 100;
    return [
        'rounds'=>max(1,min(99,(int)($src['rounds'] ?? 5))),
        'botCount'=>max(0,min(9,(int)($src['botCount'] ?? 2))),
        'botSkill'=>max(0,min(6,(int)($src['botSkill'] ?? 1))),
        'wind'=>max(0,min(2,(int)($src['wind'] ?? 1))),
        'terrain'=>$terrain,
        'backgroundCycle'=>((int)($src['backgroundCycle'] ?? 0))===1?1:0,
        'aimAssist'=>max(0,min(2,(int)($src['aimAssist'] ?? 1))),
        'startCredits'=>max(0,min(10000,(int)($src['startCredits'] ?? 2000))),
        'teamMode'=>$teamMode,
        'windChange'=>max(0,min(2,(int)($src['windChange'] ?? 1))),
        'gravity'=>$gravity,
        'drag'=>max(0,min(2,(int)($src['drag'] ?? 0))),
        'landslide'=>max(0,min(2,(int)($src['landslide'] ?? 1))),
        'walls'=>$walls,
        'meltdown'=>((int)($src['meltdown'] ?? 1))===1?1:0,
    ];
}

function weaponDefs(): array {
    return [
        'missile'=>['name'=>'SMALL MISSILE','radius'=>25.0,'damage'=>42.0,'cost'=>0,'pack'=>999,'kind'=>'shell'],
        'tracer'=>['name'=>'TRACER SHELL','radius'=>15.0,'damage'=>24.0,'cost'=>120,'pack'=>5,'kind'=>'shell'],
        'heavy'=>['name'=>'HEAVY MISSILE','radius'=>42.0,'damage'=>72.0,'cost'=>450,'pack'=>3,'kind'=>'shell'],
        'piercer'=>['name'=>'ARMOR PIERCER','radius'=>17.0,'damage'=>98.0,'cost'=>550,'pack'=>2,'kind'=>'shell'],
        'baby_nuke'=>['name'=>'BABY NUKE','radius'=>58.0,'damage'=>96.0,'cost'=>800,'pack'=>2,'kind'=>'shell'],
        'nuke'=>['name'=>'ATOMIC NUKE','radius'=>88.0,'damage'=>142.0,'cost'=>1500,'pack'=>1,'kind'=>'shell'],
        'hydrogen'=>['name'=>'HYDROGEN NUKE','radius'=>125.0,'damage'=>225.0,'cost'=>3000,'pack'=>1,'kind'=>'shell'],
        'cluster'=>['name'=>'CLUSTER BOMB','radius'=>24.0,'damage'=>36.0,'cost'=>700,'pack'=>2,'kind'=>'cluster'],
        'mirv'=>['name'=>'MIRV','radius'=>31.0,'damage'=>48.0,'cost'=>1200,'pack'=>1,'kind'=>'mirv'],
        'spread'=>['name'=>'TRIPLE SPREAD','radius'=>22.0,'damage'=>34.0,'cost'=>650,'pack'=>2,'kind'=>'spread'],
        'roller'=>['name'=>'ROLLER','radius'=>34.0,'damage'=>58.0,'cost'=>600,'pack'=>2,'kind'=>'roller'],
        'burrower'=>['name'=>'BURROWER','radius'=>28.0,'damage'=>84.0,'cost'=>750,'pack'=>2,'kind'=>'burrower'],
        'napalm'=>['name'=>'NAPALM','radius'=>23.0,'damage'=>32.0,'cost'=>650,'pack'=>2,'kind'=>'napalm'],
        'riot'=>['name'=>'RIOT BOMB','radius'=>50.0,'damage'=>0.0,'cost'=>300,'pack'=>3,'kind'=>'riot'],
        'dirt'=>['name'=>'DIRT BALL','radius'=>60.0,'damage'=>0.0,'cost'=>300,'pack'=>2,'kind'=>'dirt'],
        'laser'=>['name'=>'LASER','radius'=>18.0,'damage'=>82.0,'cost'=>900,'pack'=>2,'kind'=>'laser'],
        'jackhammer'=>['name'=>'JACKHAMMER','radius'=>18.0,'damage'=>110.0,'cost'=>950,'pack'=>1,'kind'=>'jackhammer'],
        'volcano'=>['name'=>'VOLCANO BOMB','radius'=>35.0,'damage'=>56.0,'cost'=>1400,'pack'=>1,'kind'=>'volcano'],
    ];
}

function guidanceDefs(): array {
    return [
        'ballistic'=>['name'=>'BALLISTIC COMPUTER','cost'=>850,'pack'=>1],
        'homing'=>['name'=>'VERTICAL GUIDANCE','cost'=>1100,'pack'=>1],
    ];
}

function defenseDefs(): array {
    return [
        'armor'=>['name'=>'ARMOR PLATING','cost'=>900,'pack'=>1],
        'shield'=>['name'=>'ENERGY SHIELD','cost'=>650,'pack'=>1],
        'reactive'=>['name'=>'REACTIVE ARMOR','cost'=>750,'pack'=>1],
    ];
}

function itemDefs(): array {
    return [
        'repair'=>['name'=>'FIELD REPAIR','cost'=>350,'pack'=>1],
        'teleport'=>['name'=>'TELEPORT','cost'=>900,'pack'=>1],
        'parachute'=>['name'=>'PARACHUTE','cost'=>250,'pack'=>2],
        'fan'=>['name'=>'WIND FAN','cost'=>300,'pack'=>1],
    ];
}

function newInventory(): array {
    $inventory=[];
    foreach (array_keys(weaponDefs()+guidanceDefs()+defenseDefs()+itemDefs()) as $id) $inventory[$id]=0;
    $inventory['missile']=999;
    return $inventory;
}

function makePlayer(string $id, string $nickname, string $color, bool $bot, int $credits): array {
    return [
        'id'=>$id,'nickname'=>$nickname,'color'=>$color,'isBot'=>$bot,'lastSeen'=>time(),
        'team'=>null,'credits'=>$credits,'wins'=>0,'kills'=>0,'armor'=>0,'inventory'=>newInventory(),'ready'=>false,
        'tank'=>['x'=>0.0,'y'=>0.0,'health'=>100,'maxHealth'=>100,'shield'=>0,'angle'=>45,'power'=>600,'alive'=>true],
    ];
}

function maxHealth(array $p): int { return 100 + (int)($p['armor'] ?? 0) * 25; }

function sideKey(array $p): string {
    return ($p['team'] ?? null) === null ? 'solo:'.(string)$p['id'] : 'team:'.(string)$p['team'];
}

function isEnemy(array $a, array $b): bool {
    if ((string)$a['id']===(string)$b['id']) return false;
    return ($a['team']??null)===null || ($b['team']??null)===null || $a['team']!==$b['team'];
}

function assignTeams(array &$players, string $mode): void {
    foreach ($players as $i=>&$p) {
        $p['team']=$mode==='humans_cpu' ? (!empty($p['isBot'])?2:1) : ($mode==='alternating' ? ($i%2)+1 : null);
    }
    unset($p);
}

function rngNext(int &$seed): float {
    $seed = (int)((($seed * 1664525) + 1013904223) & 0xFFFFFFFF);
    return ($seed & 0xFFFFFFFF) / 4294967296.0;
}

function generateTerrain(int &$seed, string $style): array {
    if ($style === 'random') {
        $styles = ['rolling','mountains','valleys'];
        $style = $styles[(int)floor(rngNext($seed) * count($styles)) % count($styles)];
    }
    $phase1 = rngNext($seed) * M_PI * 2;
    $phase2 = rngNext($seed) * M_PI * 2;
    $phase3 = rngNext($seed) * M_PI * 2;
    $terrain = [];
    for ($i=0; $i<TERRAIN_POINTS; $i++) {
        $t = $i / (TERRAIN_POINTS - 1);
        if ($style === 'mountains') {
            $y = 485 + 95*sin($t*M_PI*5+$phase1) + 42*sin($t*M_PI*13+$phase2);
        } elseif ($style === 'valleys') {
            $y = 485 + 105*abs(sin($t*M_PI*3+$phase1)) - 50*sin($t*M_PI*2+$phase2);
        } else {
            $y = 500 + 72*sin($t*M_PI*3+$phase1) + 32*sin($t*M_PI*7+$phase2) + 18*sin($t*M_PI*17+$phase3);
        }
        $terrain[] = (int)round(max(305,min(610,$y)));
    }
    // Two light smoothing passes retain the period feel without one-pixel spikes.
    for ($pass=0; $pass<2; $pass++) {
        $copy = $terrain;
        for ($i=1; $i<TERRAIN_POINTS-1; $i++) $terrain[$i] = (int)round(($copy[$i-1]+2*$copy[$i]+$copy[$i+1])/4);
    }
    return $terrain;
}

function terrainAt(array $terrain, float $x): float {
    $x = max(0.0,min((float)FIELD_W,$x));
    $p = $x / TERRAIN_STEP;
    $i = max(0,min(TERRAIN_POINTS-2,(int)floor($p)));
    $f = $p - $i;
    return (float)$terrain[$i] * (1-$f) + (float)$terrain[$i+1] * $f;
}

function carveInitialTankPads(array &$state): void {
    foreach ($state['players'] as $p) {
        $x=(float)$p['tank']['x'];
        $outer=TANK_PAD_HALF+TANK_PAD_FEATHER;
        $from=max(0,(int)floor(($x-$outer)/TERRAIN_STEP));
        $to=min(count($state['terrain'])-1,(int)ceil(($x+$outer)/TERRAIN_STEP));
        $padY=-INF;
        for($i=$from;$i<=$to;$i++) $padY=max($padY,(float)$state['terrain'][$i]);
        for($i=$from;$i<=$to;$i++){
            $dx=abs($i*TERRAIN_STEP-$x);
            if($dx>$outer) continue;
            $blend=1.0;
            if($dx>TANK_PAD_HALF) $blend=1.0-($dx-TANK_PAD_HALF)/TANK_PAD_FEATHER;
            $blend=max(0.0,min(1.0,$blend));
            $desired=(int)round($state['terrain'][$i]+($padY-$state['terrain'][$i])*$blend);
            // Cut dirt away only; do not build a starting platform upward.
            $state['terrain'][$i]=max((int)$state['terrain'][$i],$desired);
        }
    }
}

function settleTanks(array &$state): void {
    foreach ($state['players'] as &$p) {
        if (empty($p['tank']['alive'])) continue;
        $p['tank']['y'] = round(terrainAt($state['terrain'], (float)$p['tank']['x']) - TANK_GROUND_OFFSET, 2);
    }
    unset($p);
}

function newBattle(array &$state): void {
    $state['trailHistory'] = [];
    $seed = random_int(1, 0x7FFFFFFF);
    $state['seed'] = $seed;
    $state['backgroundSeed'] = !empty($state['settings']['backgroundCycle']) ? random_int(1, 0x7FFFFFFF) : 0;
    $state['terrain'] = generateTerrain($seed, (string)$state['settings']['terrain']);
    $state['wind'] = $state['settings']['wind'] === 0 ? 0 : random_int(-18 * $state['settings']['wind'], 18 * $state['settings']['wind']);
    $count = count($state['players']);
    $slots = [];
    for ($i=0; $i<$count; $i++) $slots[] = 70 + ($count === 1 ? 0 : $i * (FIELD_W-140)/($count-1));
    // Shuffle color/order-independent positions deterministically.
    for ($i=count($slots)-1; $i>0; $i--) {
        $j = (int)floor(rngNext($seed)*($i+1));
        [$slots[$i],$slots[$j]] = [$slots[$j],$slots[$i]];
    }
    foreach ($state['players'] as $i=>&$p) {
        $p['ready'] = false;
        $max=maxHealth($p);
        $p['tank'] = ['x'=>round($slots[$i],2),'y'=>0.0,'health'=>$max,'maxHealth'=>$max,'shield'=>0,'angle'=>45,'power'=>600,'alive'=>true];
    }
    unset($p);
    carveInitialTankPads($state);
    settleTanks($state);
    $alive = array_values(array_filter($state['players'], fn($p)=>!empty($p['tank']['alive'])));
    $state['currentPlayerId'] = $alive[0]['id'] ?? null;
    $state['turnNumber'] = 1;
    $state['turnStartedAt'] = time();
    $state['lastAction'] = null;
    $state['revision'] = (int)($state['revision'] ?? 0) + 1;
}

function playerIndex(array $state, string $id): int {
    foreach ($state['players'] as $i=>$p) if ((string)$p['id'] === $id) return $i;
    return -1;
}

function hostId(array $state): string { return (string)($state['hostId'] ?? ''); }

function activeHumanCount(array $state): int {
    $n=0;
    foreach ($state['players'] as $p) if (empty($p['isBot']) && time()-(int)($p['lastSeen']??0)<PLAYER_TIMEOUT) $n++;
    return $n;
}

function nextTurn(array &$state, string $afterId): void {
    $count = count($state['players']);
    if (!$count) { $state['currentPlayerId']=null; return; }
    $start = playerIndex($state,$afterId);
    for ($step=1; $step<=$count; $step++) {
        $i = (($start < 0 ? -1 : $start) + $step) % $count;
        if (!empty($state['players'][$i]['tank']['alive'])) {
            $state['currentPlayerId'] = $state['players'][$i]['id'];
            $state['turnNumber'] = (int)($state['turnNumber'] ?? 0) + 1;
            $state['turnStartedAt'] = time();
            $change=(int)($state['settings']['windChange']??0);
            $windLevel=(int)($state['settings']['wind']??0);
            if ($change>0 && $windLevel>0) {
                $state['wind']=max(-36*$windLevel,min(36*$windLevel,(int)$state['wind']+random_int(-2*$change,2*$change)));
            }
            return;
        }
    }
    $state['currentPlayerId'] = null;
}

function applyTerrainCircle(array &$terrain, float $cx, float $cy, float $radius, bool $addDirt): void {
    $from=max(0,(int)floor(($cx-$radius)/TERRAIN_STEP));
    $to=min(TERRAIN_POINTS-1,(int)ceil(($cx+$radius)/TERRAIN_STEP));
    for ($i=$from; $i<=$to; $i++) {
        $x=$i*TERRAIN_STEP;
        $dx=$x-$cx;
        if (abs($dx)>$radius) continue;
        $dy=sqrt(max(0.0,$radius*$radius-$dx*$dx));
        if ($addDirt) {
            $surface=$cy-$dy*0.9;
            $terrain[$i]=(int)round(max(120,min((float)$terrain[$i],$surface)));
        } else {
            $bottom=$cy+$dy;
            $terrain[$i]=(int)round(min(FIELD_H-20,max((float)$terrain[$i],$bottom)));
        }
    }
}

function traceProjectile(array $state, array $shooter, int $angle, int $power, bool $laser=false): array {
    $rad=deg2rad($angle);
    $x=(float)$shooter['tank']['x'] + cos($rad)*19;
    $y=(float)$shooter['tank']['y'] - 12 - sin($rad)*19;
    $vx=cos($rad)*($laser?8:$power/20.0);
    $vy=-sin($rad)*($laser?8:$power/20.0);
    $path=[['x'=>round($x,1),'y'=>round($y,1)]];
    $hitX=$x; $hitY=$y; $escaped=false;
    $gravity=.35*((int)($state['settings']['gravity']??100)/100);
    $drag=1-(int)($state['settings']['drag']??0)*.00055;
    $walls=(string)($state['settings']['walls']??'open');
    for ($step=0; $step<($laser?360:760); $step++) {
        if (!$laser) {
            $vx*=$drag; $vy*=$drag;
            $vx += (float)$state['wind'] * 0.0009;
            $vy += $gravity;
        }
        $x += $vx * ($laser?1:.45);
        $y += $vy * ($laser?1:.45);
        if ($step % 3 === 0) $path[]=['x'=>round($x,1),'y'=>round($y,1)];
        if ($x<0 || $x>FIELD_W) {
            if ($walls==='wrap') {
                $x=$x<0?$x+FIELD_W:$x-FIELD_W;
                $path[]=['x'=>round($x,1),'y'=>round($y,1)];
            } elseif ($walls==='bounce') {
                $x=$x<0?-$x:2*FIELD_W-$x;
                $vx=$x<FIELD_W/2?abs($vx):-abs($vx);
                $path[]=['x'=>round($x,1),'y'=>round($y,1)];
            } elseif ($x < -40 || $x > FIELD_W+40) { $escaped=true; break; }
        }
        if ($walls==='bounce' && ($y<0 || $y>FIELD_H)) {
            $y=$y<0?-$y:2*FIELD_H-$y;
            $vy=$y<FIELD_H/2?abs($vy):-abs($vy);
            $path[]=['x'=>round($x,1),'y'=>round($y,1)];
        } elseif ($y > FIELD_H+40) { $escaped=true; break; }
        if ($step>5 && $x>=0 && $x<=FIELD_W && $y>=terrainAt($state['terrain'],$x)) {
            $hitX=$x; $hitY=terrainAt($state['terrain'],$x); break;
        }
        foreach ($state['players'] as $p) {
            if (empty($p['tank']['alive']) || ($step<8 && $p['id']===$shooter['id'])) continue;
            $dx=$x-(float)$p['tank']['x']; $dy=$y-(float)$p['tank']['y'];
            if ($dx*$dx+$dy*$dy<18*18) { $hitX=$x; $hitY=$y; break 2; }
        }
    }
    return ['path'=>$path,'hitX'=>$hitX,'hitY'=>$hitY,'escaped'=>$escaped];
}

function solveShot(array $state, array $shooter, string $weaponId, array $target): array {
    $left=(float)$target['tank']['x']<(float)$shooter['tank']['x'];
    $best=['score'=>INF,'angle'=>$left?135:45,'power'=>550];
    for ($angle=$left?95:10; $angle<=($left?170:85); $angle+=5) {
        for ($power=160; $power<=1000; $power+=45) {
            $trace=traceProjectile($state,$shooter,$angle,$power,false);
            if ($trace['escaped']) continue;
            $score=hypot((float)$trace['hitX']-(float)$target['tank']['x'],(float)$trace['hitY']-(float)$target['tank']['y']);
            if ($score<$best['score']) $best=['score'=>$score,'angle'=>$angle,'power'=>$power];
        }
    }
    return $best;
}

function makeImpact(float $x, float $y, array $def): array {
    return ['x'=>round(max(2.0,min(FIELD_W-2.0,$x)),1),'y'=>round($y,1),'radius'=>$def['radius'],'damage'=>$def['damage']];
}

function simulateShot(array $state, array $shooter, string $weaponId, int $angle, int $power, string $guidance='none', ?string $targetId=null): array {
    $defs=weaponDefs();
    $def=$defs[$weaponId]??$defs['missile'];
    $target=null;
    foreach ($state['players'] as $candidate) if ((string)$candidate['id']===(string)$targetId && !empty($candidate['tank']['alive'])) { $target=$candidate; break; }
    if ($guidance==='ballistic' && $target) {
        $solution=solveShot($state,$shooter,$weaponId,$target);
        $angle=(int)$solution['angle']; $power=(int)$solution['power'];
    }
    $angles=$def['kind']==='spread'?[$angle-5,$angle,$angle+5]:[$angle];
    $traces=[];
    foreach ($angles as $a) $traces[]=traceProjectile($state,$shooter,max(5,min(175,$a)),$power,$def['kind']==='laser');
    $primaryIndex=(int)floor(count($traces)/2);
    if ($guidance==='homing' && $target && empty($traces[$primaryIndex]['escaped'])) {
        $path=$traces[$primaryIndex]['path']; $apex=0;
        foreach ($path as $i=>$point) if ((float)$point['y']<(float)$path[$apex]['y']) $apex=$i;
        if ((float)$path[$apex]['y']<(float)$target['tank']['y']-35) {
            $start=$path[$apex]; $path=array_slice($path,0,$apex+1);
            for ($i=1;$i<=14;$i++) $path[]=[
                'x'=>round((float)$start['x']+((float)$target['tank']['x']-(float)$start['x'])*$i/14,1),
                'y'=>round((float)$start['y']+((float)$target['tank']['y']-(float)$start['y'])*$i/14,1),
            ];
            $traces[$primaryIndex]['path']=$path;
            $traces[$primaryIndex]['hitX']=(float)$target['tank']['x'];
            $traces[$primaryIndex]['hitY']=(float)$target['tank']['y'];
            $traces[$primaryIndex]['escaped']=false;
        }
    }
    $paths=array_map(fn($trace)=>$trace['path'],$traces);
    $impacts=[];
    foreach ($traces as $trace) if (empty($trace['escaped'])) $impacts[]=makeImpact((float)$trace['hitX'],(float)$trace['hitY'],$def);
    $baseIndex=(int)floor(count($impacts)/2); $base=$impacts[$baseIndex]??null;
    $secondary=[];
    if ($base && $def['kind']==='cluster') $secondary=[-70,-35,0,35,70];
    if ($base && $def['kind']==='mirv') $secondary=[-100,-50,0,50,100];
    if ($base && $def['kind']==='napalm') $secondary=[-72,-48,-24,0,24,48,72];
    if ($base && $def['kind']==='volcano') $secondary=[-160,-80,0,80,160];
    if ($secondary) {
        $impacts=[];
        foreach ($secondary as $off) {
            $ix=max(4.0,min(FIELD_W-4.0,(float)$base['x']+$off));
            $impacts[]=makeImpact($ix,terrainAt($state['terrain'],$ix),$def);
        }
    }
    if ($base && in_array($def['kind'],['burrower','jackhammer'],true)) {
        $impacts[$baseIndex]['y']+=($def['kind']==='burrower'?35:62);
    }
    if ($base && $def['kind']==='roller') {
        $x=(float)$base['x'];
        for ($i=0;$i<35;$i++) {
            $left=terrainAt($state['terrain'],max(4.0,$x-4));
            $right=terrainAt($state['terrain'],min(FIELD_W-4.0,$x+4));
            $next=$right>$left?$x+4:$x-4;
            if ($next<4 || $next>FIELD_W-4 || abs(terrainAt($state['terrain'],$next)-terrainAt($state['terrain'],$x))<.2) break;
            $x=$next; $traces[$primaryIndex]['path'][]=['x'=>round($x,1),'y'=>round(terrainAt($state['terrain'],$x)-4,1)];
        }
        $impacts[$baseIndex]['x']=round($x,1); $impacts[$baseIndex]['y']=round(terrainAt($state['terrain'],$x),1);
        $paths[$primaryIndex]=$traces[$primaryIndex]['path'];
    }
    return ['path'=>$paths[$primaryIndex]??[],'paths'=>$paths,'impacts'=>$impacts,'escaped'=>empty($impacts),'weapon'=>$weaponId,'angle'=>$angle,'power'=>$power,'guidance'=>$guidance,'targetId'=>$targetId];
}

function applyLandslide(array &$state): void {
    $level=(int)($state['settings']['landslide']??0);
    if ($level===0) return;
    $passes=$level===2?9:4; $limit=$level===2?6:11;
    for ($pass=0;$pass<$passes;$pass++) {
        $copy=$state['terrain'];
        for ($i=1;$i<TERRAIN_POINTS-1;$i++) {
            $average=((float)$copy[$i-1]+(float)$copy[$i+1])/2;
            if (abs((float)$copy[$i]-$average)>$limit) $state['terrain'][$i]=(int)round((float)$copy[$i]+($average-(float)$copy[$i])*.38);
        }
    }
}

function damageTank(array &$player, int $amount): int {
    if ($amount<1 || empty($player['tank']['alive'])) return 0;
    if ((int)($player['inventory']['reactive']??0)>0) {
        $player['inventory']['reactive']--; $amount=(int)ceil($amount/2);
    }
    $absorbed=min((int)$player['tank']['shield'],$amount);
    $player['tank']['shield']-=$absorbed;
    $player['tank']['health']=max(0,(int)$player['tank']['health']-($amount-$absorbed));
    if ($player['tank']['health']<=0) $player['tank']['alive']=false;
    return $amount;
}

function finishRoundIfNeeded(array &$state): bool {
    $alive=array_values(array_filter($state['players'],fn($p)=>!empty($p['tank']['alive'])));
    $sides=[];
    foreach ($alive as $p) $sides[sideKey($p)]=true;
    if (count($sides)>1) return false;
    $winningSide=$alive?sideKey($alive[0]):null; $winners=[];
    if ($winningSide!==null) foreach ($state['players'] as $i=>$p) if (sideKey($p)===$winningSide) $winners[]=$i;
    foreach ($winners as $idx) {
        $state['players'][$idx]['wins']=(int)$state['players'][$idx]['wins']+1;
        $state['players'][$idx]['credits']=(int)$state['players'][$idx]['credits']+1200;
    }
    foreach ($state['players'] as &$p) $p['credits']=(int)$p['credits']+400;
    unset($p);
    $state['roundWinnerId']=$winners?(string)$state['players'][$winners[0]]['id']:null;
    $state['roundWinnerTeam']=$alive[0]['team']??null;
    $state['currentPlayerId']=null;
    $state['phase']=((int)$state['round'] >= (int)$state['settings']['rounds']) ? 'match_over' : 'round_over';
    return true;
}

function applyShot(array &$state, string $actorId, string $weaponId, int $angle, int $power, string $guidance, ?string $targetId, string $actionId): array {
    if (($state['phase']??'')!=='playing') return ['error'=>'The battle is not accepting shots.','status'=>409];
    if ((string)($state['currentPlayerId']??'')!==$actorId) return ['error'=>'It is not that tank\'s turn.','status'=>409];
    $idx=playerIndex($state,$actorId);
    if ($idx<0 || empty($state['players'][$idx]['tank']['alive'])) return ['error'=>'Tank is not active.','status'=>409];
    if ($actionId!=='' && (string)($state['lastActionId']??'')===$actionId) return ['ok'=>true,'duplicate'=>true];
    $defs=weaponDefs();
    if (!isset($defs[$weaponId])) $weaponId='missile';
    $stock=(int)($state['players'][$idx]['inventory'][$weaponId]??0);
    if ($weaponId!=='missile' && $stock<1) return ['error'=>'That weapon is out of stock.','status'=>409];
    if (!isset(guidanceDefs()[$guidance])) $guidance='none';
    if ($guidance!=='none' && (int)($state['players'][$idx]['inventory'][$guidance]??0)<1) return ['error'=>'That guidance system is out of stock.','status'=>409];
    if ($guidance!=='none') {
        $targetIndex=playerIndex($state,(string)$targetId);
        if ($targetIndex<0 || empty($state['players'][$targetIndex]['tank']['alive']) || !isEnemy($state['players'][$idx],$state['players'][$targetIndex])) return ['error'=>'Select an active enemy target.','status'=>409];
    }
    $angle=max(5,min(175,$angle)); $power=max(100,min(1000,$power));
    if ($weaponId!=='missile') $state['players'][$idx]['inventory'][$weaponId]=$stock-1;
    if ($guidance!=='none') $state['players'][$idx]['inventory'][$guidance]--;
    $shot=simulateShot($state,$state['players'][$idx],$weaponId,$angle,$power,$guidance,$targetId);
    $state['players'][$idx]['tank']['angle']=$shot['angle'];
    $state['players'][$idx]['tank']['power']=$shot['power'];
    $shot['id']=bin2hex(random_bytes(6)); $shot['actorId']=$actorId;
    $shot['createdAt']=(int)round(microtime(true)*1000);
    $damageByPlayer=[]; $kills=[]; $melted=[]; $oldY=[];
    foreach ($state['players'] as $p) $oldY[(string)$p['id']]=(float)$p['tank']['y'];
    for ($impactIndex=0;$impactIndex<count($shot['impacts']);$impactIndex++) {
        $impact=$shot['impacts'][$impactIndex]; $isMeltdown=!empty($impact['meltdown']);
        applyTerrainCircle($state['terrain'],(float)$impact['x'],(float)$impact['y'],(float)$impact['radius'],$weaponId==='dirt'&&!$isMeltdown);
        if (($weaponId==='dirt' || $weaponId==='riot') && !$isMeltdown) continue;
        foreach ($state['players'] as $pi=>&$target) {
            if (empty($target['tank']['alive'])) continue;
            $dx=(float)$target['tank']['x']-(float)$impact['x'];
            $dy=(float)$target['tank']['y']-(float)$impact['y'];
            $dist=sqrt($dx*$dx+$dy*$dy);
            $reach=(float)$impact['radius']+16;
            if ($dist>=$reach) continue;
            $damage=(int)round((float)$impact['damage']*(1-$dist/$reach));
            if ($damage<1) continue;
            $dealt=damageTank($target,$damage);
            $damageByPlayer[$target['id']]=($damageByPlayer[$target['id']]??0)+$dealt;
            if ($target['tank']['health']<=0) {
                $target['tank']['alive']=false;
                if (!in_array($target['id'],$kills,true)) {
                    $kills[]=$target['id'];
                    if (!empty($state['settings']['meltdown']) && empty($melted[$target['id']])) {
                        $melted[$target['id']]=true;
                        $shot['impacts'][]=['x'=>$target['tank']['x'],'y'=>$target['tank']['y'],'radius'=>38.0,'damage'=>62.0,'meltdown'=>true];
                    }
                }
            }
        }
        unset($target);
    }
    applyLandslide($state);
    foreach ($state['players'] as &$target) {
        if (empty($target['tank']['alive'])) continue;
        $nextY=terrainAt($state['terrain'],(float)$target['tank']['x'])-TANK_GROUND_OFFSET;
        $drop=$nextY-($oldY[(string)$target['id']]??$nextY); $hazard=0;
        if ($drop>34) {
            if ((int)($target['inventory']['parachute']??0)>0) $target['inventory']['parachute']--;
            else $hazard=(int)round(($drop-24)*1.25);
        } elseif ($drop<-24) $hazard=(int)round(28+abs($drop)*1.8);
        if ($hazard>0) {
            $dealt=damageTank($target,$hazard);
            $damageByPlayer[$target['id']]=($damageByPlayer[$target['id']]??0)+$dealt;
            if (empty($target['tank']['alive']) && !in_array($target['id'],$kills,true)) {
                $kills[]=$target['id'];
                if (!empty($state['settings']['meltdown']) && empty($melted[$target['id']])) {
                    $melted[$target['id']]=true;
                    $shot['impacts'][]=['x'=>$target['tank']['x'],'y'=>$target['tank']['y'],'radius'=>38.0,'damage'=>62.0,'meltdown'=>true];
                    applyTerrainCircle($state['terrain'],(float)$target['tank']['x'],(float)$target['tank']['y'],38.0,false);
                }
            }
        }
        $target['tank']['y']=round($nextY,2);
    }
    unset($target);
    $earned=0;
    foreach ($damageByPlayer as $hitId=>$damage) {
        $targetIndex=playerIndex($state,(string)$hitId);
        if ($targetIndex>=0 && isEnemy($state['players'][$idx],$state['players'][$targetIndex])) $earned+=(int)$damage*4;
    }
    foreach ($kills as $killedId) {
        $targetIndex=playerIndex($state,(string)$killedId);
        if ($targetIndex>=0 && isEnemy($state['players'][$idx],$state['players'][$targetIndex])) { $earned+=650; $state['players'][$idx]['kills']++; }
    }
    $state['players'][$idx]['credits']=(int)$state['players'][$idx]['credits']+$earned;
    $shot['damage']=$damageByPlayer; $shot['kills']=$kills; $shot['earned']=$earned;
    if ((int)($state['settings']['aimAssist']??1)===2) {
        if (!isset($state['trailHistory']) || !is_array($state['trailHistory'])) $state['trailHistory']=[];
        $paths=!empty($shot['paths'])?$shot['paths']:(!empty($shot['path'])?[$shot['path']]:[]);
        if ($paths) {
            $state['trailHistory'][]=[
                'shotId'=>(string)$shot['id'],
                'actorId'=>$actorId,
                'color'=>(string)($state['players'][$idx]['color']??'#ffffff'),
                'paths'=>$paths
            ];
        }
    }
    $state['lastAction']=$shot; $state['lastActionId']=$actionId; $state['revision']=(int)$state['revision']+1;
    if (!finishRoundIfNeeded($state)) nextTurn($state,$actorId);
    return ['ok'=>true,'shot'=>$shot];
}

function useItem(array &$state, string $actorId, string $itemId, string $actionId): array {
    if (($state['phase']??'')!=='playing' || (string)($state['currentPlayerId']??'')!==$actorId) return ['error'=>'It is not that tank\'s turn.','status'=>409];
    if (!in_array($itemId,['repair','shield','teleport','fan'],true)) return ['error'=>'Unknown field item.','status'=>400];
    $idx=playerIndex($state,$actorId);
    if ($idx<0 || (int)($state['players'][$idx]['inventory'][$itemId]??0)<1) return ['error'=>'That item is out of stock.','status'=>409];
    $state['players'][$idx]['inventory'][$itemId]--;
    $tank=&$state['players'][$idx]['tank'];
    if ($itemId==='repair') $tank['health']=min((int)($tank['maxHealth']??maxHealth($state['players'][$idx])),(int)$tank['health']+45);
    if ($itemId==='shield') $tank['shield']=min(120,(int)$tank['shield']+60);
    if ($itemId==='teleport') {
        $tank['x']=random_int(60,FIELD_W-60);
        $tank['y']=round(terrainAt($state['terrain'],(float)$tank['x'])-TANK_GROUND_OFFSET,2);
    }
    if ($itemId==='fan') {
        $wind=(int)$state['wind'];
        $state['wind']=(int)($wind===0?(random_int(0,1)===0?-18:18):-$wind/abs($wind)*min(36,abs($wind)+8));
    }
    $state['lastAction']=['id'=>bin2hex(random_bytes(6)),'actorId'=>$actorId,'item'=>$itemId,'createdAt'=>(int)round(microtime(true)*1000)];
    $state['lastActionId']=$actionId; $state['revision']=(int)$state['revision']+1;
    nextTurn($state,$actorId);
    return ['ok'=>true];
}

function cpuShop(array &$p): void {
    $catalog=weaponDefs()+guidanceDefs()+defenseDefs()+itemDefs();
    foreach (['armor','shield','heavy','cluster','ballistic','repair','reactive','mirv','nuke'] as $id) {
        $def=$catalog[$id];
        if ($id==='armor' && (int)$p['credits']>=$def['cost'] && (int)($p['armor']??0)<2) { $p['credits']-=$def['cost']; $p['armor']++; continue; }
        if ((int)$p['credits'] >= $def['cost'] && (int)($p['inventory'][$id]??0)<($id==='heavy'?6:2)) {
            $p['credits']-=$def['cost']; $p['inventory'][$id]+=$def['pack'];
        }
    }
    $p['ready']=true;
}

function publicState(array $room, string $me): array {
    $state=$room['state'];
    foreach ($state['players'] as &$p) {
        $p['isMe']=((string)$p['id']===$me);
        $p['connected']=!empty($p['isBot']) || time()-(int)($p['lastSeen']??0)<PLAYER_TIMEOUT;
        unset($p['lastSeen']);
    }
    unset($p);
    return [
        'code'=>$room['code'],'name'=>$room['name'],'isPrivate'=>(bool)$room['private'],
        'maxPlayers'=>(int)$room['max_players'],'serverTime'=>(int)round(microtime(true)*1000),
    ]+$state;
}

function pruneRooms(): void {
    $cut=time()-EMPTY_ROOM_TIMEOUT;
    foreach (storedRooms() as $row) {
        $state=$row['state']??[];
        if ((int)($row['updated_at']??0)<$cut ||
            (activeHumanCount($state)===0 && time()-(int)($state['createdAt']??0)>PLAYER_TIMEOUT)) {
            deleteRoom((string)$row['code']);
        }
    }
}

$me=token();
$api=(string)($_GET['api']??'');
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();

if ($api==='catalog') jsonOut(['ok'=>true,'weapons'=>weaponDefs(),'guidance'=>guidanceDefs(),'defenses'=>defenseDefs(),'items'=>itemDefs()]);

if ($api==='list') {
    pruneRooms(); $rooms=[];
    foreach (array_slice(storedRooms(),0,40) as $row) {
        if (!empty($row['private'])) continue;
        $st=$row['state']??[];
        $humans=count(array_filter($st['players']??[],fn($p)=>empty($p['isBot'])));
        $rooms[]=['code'=>$row['code'],'name'=>$row['name'],'players'=>$humans,'maxPlayers'=>(int)$row['max_players'],'phase'=>$st['phase']??'lobby','round'=>$st['round']??0,'createdAt'=>(int)$row['created_at']];
    }
    jsonOut(['ok'=>true,'rooms'=>$rooms]);
}

if ($api==='state') {
    $code=cleanCode((string)($_GET['code']??''));
    if ($code==='') jsonOut(['ok'=>false,'error'=>'Room code required.'],400);
    $mutation=mutateRoom($code,function(array &$room) use($me) {
        $idx=playerIndex($room['state'],$me);
        if ($idx<0) return ['error'=>'You are no longer in this room.','status'=>410,'noSave'=>true];
        $room['state']['players'][$idx]['lastSeen']=time();
        return ['ok'=>true];
    });
    if (!$mutation['room']) jsonOut(['ok'=>false,'error'=>'Room not found.'],404);
    if (!empty($mutation['result']['error'])) jsonOut(['ok'=>false,'error'=>$mutation['result']['error']],(int)$mutation['result']['status']);
    jsonOut(['ok'=>true,'state'=>publicState($mutation['room'],$me)]);
}

if ($_SERVER['REQUEST_METHOD']!=='POST') jsonOut(['ok'=>false,'error'=>'Unknown request.'],405);
$action=(string)($_POST['action']??'');
$code=cleanCode((string)($_POST['code']??''));

if ($action==='create') {
    pruneRooms();
    $nickname=cleanText((string)($_POST['nickname']??''),20,'Commander');
    $name=cleanText((string)($_POST['name']??''),42,$nickname."'s Battlefield");
    $settings=settingsFrom($_POST);
    $maxPlayers=max(2,min(MAX_PLAYERS,(int)($_POST['maxPlayers']??4)));
    $private=((string)($_POST['private']??'0'))==='1'?1:0;
    $code=generateCode(); $now=time();
    $state=[
        'phase'=>'lobby','hostId'=>$me,'settings'=>$settings,'players'=>[
            makePlayer($me,$nickname,COLORS[0],false,$settings['startCredits'])
        ],'round'=>0,'revision'=>0,'createdAt'=>$now,'lastAction'=>null,'trailHistory'=>[],
    ];
    insertRoom(['code'=>$code,'name'=>$name,'state'=>$state,'private'=>$private,'max_players'=>$maxPlayers,'created_at'=>$now,'updated_at'=>$now]);
    jsonOut(['ok'=>true,'code'=>$code]);
}

if ($action==='join') {
    if ($code==='') jsonOut(['ok'=>false,'error'=>'Room code required.'],400);
    $nickname=cleanText((string)($_POST['nickname']??''),20,'Commander');
    $mutation=mutateRoom($code,function(array &$room) use($me,$nickname) {
        if (($room['state']['phase']??'lobby')!=='lobby') return ['error'=>'That battle has already started.','status'=>409,'noSave'=>true];
        foreach ($room['state']['players'] as $p) {
            if ($p['id']===$me) return ['ok'=>true];
            if (empty($p['isBot']) && strcasecmp((string)$p['nickname'],$nickname)===0) return ['error'=>'That commander name is already in use.','status'=>409,'noSave'=>true];
        }
        $humans=count(array_filter($room['state']['players'],fn($p)=>empty($p['isBot'])));
        if ($humans>=(int)$room['max_players']) return ['error'=>'That room is full.','status'=>409,'noSave'=>true];
        $color=COLORS[$humans%count(COLORS)];
        $room['state']['players'][]=makePlayer($me,$nickname,$color,false,(int)$room['state']['settings']['startCredits']);
        $room['state']['revision']++;
        return ['ok'=>true];
    });
    if (!$mutation['room']) jsonOut(['ok'=>false,'error'=>'Room not found.'],404);
    if (!empty($mutation['result']['error'])) jsonOut(['ok'=>false,'error'=>$mutation['result']['error']],(int)$mutation['result']['status']);
    jsonOut(['ok'=>true,'code'=>$code]);
}

if ($action==='leave') {
    if ($code==='') jsonOut(['ok'=>true]);
    $mutation=mutateRoom($code,function(array &$room) use($me) {
        $wasHost=hostId($room['state'])===$me;
        $room['state']['players']=array_values(array_filter($room['state']['players'],fn($p)=>$p['id']!==$me));
        $humans=array_values(array_filter($room['state']['players'],fn($p)=>empty($p['isBot'])));
        if ($wasHost && $humans) $room['state']['hostId']=$humans[0]['id'];
        if (($room['state']['currentPlayerId']??'')===$me) nextTurn($room['state'],$me);
        return ['empty'=>empty($humans)];
    });
    if ($mutation['room'] && !empty($mutation['result']['empty'])) deleteRoom($code);
    jsonOut(['ok'=>true]);
}

if ($code==='') jsonOut(['ok'=>false,'error'=>'Room code required.'],400);

if ($action==='update_settings') {
    $mutation=mutateRoom($code,function(array &$room) use($me) {
        if (hostId($room['state'])!==$me) return ['error'=>'Only the host can change battle settings.','status'=>403,'noSave'=>true];
        if (($room['state']['phase']??'')!=='lobby') return ['error'=>'Settings are locked after launch.','status'=>409,'noSave'=>true];
        $room['state']['settings']=settingsFrom($_POST+$room['state']['settings']); $room['state']['revision']++;
        return ['ok'=>true];
    });
} elseif ($action==='start_game') {
    $mutation=mutateRoom($code,function(array &$room) use($me) {
        $state=&$room['state'];
        if (hostId($state)!==$me) return ['error'=>'Only the host can launch the battle.','status'=>403,'noSave'=>true];
        if (($state['phase']??'')!=='lobby') return ['error'=>'Battle is already underway.','status'=>409,'noSave'=>true];
        $humanCount=count(array_filter($state['players'],fn($p)=>empty($p['isBot'])));
        $total=min(MAX_PLAYERS,$humanCount+(int)$state['settings']['botCount']);
        for ($i=$humanCount;$i<$total;$i++) $state['players'][]=makePlayer('bot-'.bin2hex(random_bytes(4)),'CPU '.($i-$humanCount+1),COLORS[$i%count(COLORS)],true,(int)$state['settings']['startCredits']);
        $state['settings']['botCount']=$total-$humanCount;
        if (count($state['players'])<2) return ['error'=>'Add at least one CPU or wait for another commander.','status'=>409,'noSave'=>true];
        assignTeams($state['players'],(string)$state['settings']['teamMode']);
        $state['trailHistory']=[];
        $state['round']=0; newBattle($state); $state['phase']='shop'; $state['currentPlayerId']=null;
        foreach ($state['players'] as &$p) if (!empty($p['isBot'])) cpuShop($p);
        unset($p);
        return ['ok'=>true];
    });
} elseif ($action==='aim') {
    $angle=max(5,min(175,(int)($_POST['angle']??45)));
    $power=max(100,min(1000,(int)($_POST['power']??600)));
    $mutation=mutateRoom($code,function(array &$room) use($me,$angle,$power) {
        $state=&$room['state'];
        if (($state['phase']??'')!=='playing') return ['error'=>'Battle is not active.','status'=>409,'noSave'=>true];
        if ((string)($state['currentPlayerId']??'')!==$me) return ['error'=>'It is not your turn.','status'=>409,'noSave'=>true];
        $idx=playerIndex($state,$me);
        if ($idx<0 || !empty($state['players'][$idx]['isBot'])) return ['error'=>'Invalid commander.','status'=>403,'noSave'=>true];
        $state['players'][$idx]['tank']['angle']=$angle;
        $state['players'][$idx]['tank']['power']=$power;
        $state['revision']=(int)($state['revision']??0)+1;
        return ['ok'=>true];
    });
} elseif ($action==='fire' || $action==='cpu_fire') {
    $weapon=(string)($_POST['weapon']??'missile'); $angle=(int)($_POST['angle']??45); $power=(int)($_POST['power']??600); $actionId=substr((string)($_POST['actionId']??''),0,50);
    $guidance=(string)($_POST['guidance']??'none'); $targetId=substr((string)($_POST['targetId']??''),0,64);
    $actor=(string)($_POST['actorId']??$me);
    $mutation=mutateRoom($code,function(array &$room) use($me,$action,$actor,$weapon,$angle,$power,$guidance,$targetId,$actionId) {
        if ($action==='fire' && $actor!==$me) return ['error'=>'Invalid commander.','status'=>403,'noSave'=>true];
        if ($action==='cpu_fire' && hostId($room['state'])!==$me) return ['error'=>'Only the host advances CPU turns.','status'=>403,'noSave'=>true];
        $idx=playerIndex($room['state'],$actor);
        if ($idx<0 || ($action==='cpu_fire' && empty($room['state']['players'][$idx]['isBot']))) return ['error'=>'Invalid CPU tank.','status'=>400,'noSave'=>true];
        return applyShot($room['state'],$actor,$weapon,$angle,$power,$guidance,$targetId!==''?$targetId:null,$actionId);
    });
} elseif ($action==='use_item' || $action==='cpu_item') {
    $item=(string)($_POST['item']??''); $actor=(string)($_POST['actorId']??$me); $actionId=substr((string)($_POST['actionId']??''),0,50);
    $mutation=mutateRoom($code,function(array &$room) use($me,$action,$actor,$item,$actionId) {
        if ($action==='use_item' && $actor!==$me) return ['error'=>'Invalid commander.','status'=>403,'noSave'=>true];
        if ($action==='cpu_item' && hostId($room['state'])!==$me) return ['error'=>'Only the host advances CPU turns.','status'=>403,'noSave'=>true];
        return useItem($room['state'],$actor,$item,$actionId);
    });
} elseif ($action==='pass') {
    $mutation=mutateRoom($code,function(array &$room) use($me) {
        if (($room['state']['phase']??'')!=='playing' || (string)$room['state']['currentPlayerId']!==$me) return ['error'=>'It is not your turn.','status'=>409,'noSave'=>true];
        $room['state']['lastAction']=['id'=>bin2hex(random_bytes(6)),'actorId'=>$me,'pass'=>true,'createdAt'=>(int)round(microtime(true)*1000)];
        $room['state']['revision']++; nextTurn($room['state'],$me); return ['ok'=>true];
    });
} elseif ($action==='open_shop') {
    $mutation=mutateRoom($code,function(array &$room) use($me) {
        if (hostId($room['state'])!==$me || ($room['state']['phase']??'')!=='round_over') return ['error'=>'Only the host can open the armory now.','status'=>403,'noSave'=>true];
        $room['state']['phase']='shop';
        foreach ($room['state']['players'] as &$p) { $p['ready']=false; if (!empty($p['isBot'])) cpuShop($p); }
        unset($p); $room['state']['revision']++; return ['ok'=>true];
    });
} elseif ($action==='buy') {
    $buyId=(string)($_POST['item']??'');
    $mutation=mutateRoom($code,function(array &$room) use($me,$buyId) {
        if (($room['state']['phase']??'')!=='shop') return ['error'=>'The armory is closed.','status'=>409,'noSave'=>true];
        $idx=playerIndex($room['state'],$me); if ($idx<0) return ['error'=>'Commander not found.','status'=>410,'noSave'=>true];
        $all=weaponDefs()+guidanceDefs()+defenseDefs()+itemDefs(); if (!isset($all[$buyId]) || $buyId==='missile') return ['error'=>'Unknown armory item.','status'=>400,'noSave'=>true];
        $def=$all[$buyId]; if ((int)$room['state']['players'][$idx]['credits']<(int)$def['cost']) return ['error'=>'Not enough credits.','status'=>409,'noSave'=>true];
        if ($buyId==='armor' && (int)($room['state']['players'][$idx]['armor']??0)>=3) return ['error'=>'Armor plating is already at maximum.','status'=>409,'noSave'=>true];
        $room['state']['players'][$idx]['credits']-=(int)$def['cost'];
        if ($buyId==='armor') $room['state']['players'][$idx]['armor']=(int)($room['state']['players'][$idx]['armor']??0)+1;
        else $room['state']['players'][$idx]['inventory'][$buyId]=(int)($room['state']['players'][$idx]['inventory'][$buyId]??0)+(int)$def['pack'];
        $room['state']['players'][$idx]['ready']=false; $room['state']['revision']++; return ['ok'=>true];
    });
} elseif ($action==='shop_commit') {
    $rawItems=(string)($_POST['items']??'');
    $buyIds=array_values(array_unique(array_filter(array_map('trim',explode(',',$rawItems)),fn($v)=>$v!=='')));
    $mutation=mutateRoom($code,function(array &$room) use($me,$buyIds) {
        if (($room['state']['phase']??'')!=='shop') return ['error'=>'The armory is closed.','status'=>409,'noSave'=>true];
        $idx=playerIndex($room['state'],$me); if ($idx<0) return ['error'=>'Commander not found.','status'=>410,'noSave'=>true];
        if (!empty($room['state']['players'][$idx]['ready'])) return ['error'=>'Purchases are already locked.','status'=>409,'noSave'=>true];

        $all=weaponDefs()+guidanceDefs()+defenseDefs()+itemDefs();
        $total=0;
        foreach ($buyIds as $buyId) {
            if (!isset($all[$buyId]) || $buyId==='missile') return ['error'=>'Unknown armory item.','status'=>400,'noSave'=>true];
            if ($buyId==='armor' && (int)($room['state']['players'][$idx]['armor']??0)>=3) return ['error'=>'Armor plating is already at maximum.','status'=>409,'noSave'=>true];
            $total+=(int)$all[$buyId]['cost'];
        }
        if ((int)$room['state']['players'][$idx]['credits']<$total) return ['error'=>'Not enough credits for the selected loadout.','status'=>409,'noSave'=>true];

        $room['state']['players'][$idx]['credits']-=$total;
        foreach ($buyIds as $buyId) {
            $def=$all[$buyId];
            if ($buyId==='armor') $room['state']['players'][$idx]['armor']=(int)($room['state']['players'][$idx]['armor']??0)+1;
            else $room['state']['players'][$idx]['inventory'][$buyId]=(int)($room['state']['players'][$idx]['inventory'][$buyId]??0)+(int)$def['pack'];
        }
        $room['state']['players'][$idx]['ready']=true;
        $room['state']['revision']++;
        return ['ok'=>true];
    });
} elseif ($action==='ready') {
    $mutation=mutateRoom($code,function(array &$room) use($me) {
        if (($room['state']['phase']??'')!=='shop') return ['error'=>'The armory is closed.','status'=>409,'noSave'=>true];
        $idx=playerIndex($room['state'],$me); if ($idx<0) return ['error'=>'Commander not found.','status'=>410,'noSave'=>true];
        $room['state']['players'][$idx]['ready']=true; $room['state']['revision']++; return ['ok'=>true];
    });
} elseif ($action==='launch_round') {
    $mutation=mutateRoom($code,function(array &$room) use($me) {
        $state=&$room['state'];
        if (hostId($state)!==$me || ($state['phase']??'')!=='shop') return ['error'=>'Only the host can launch the next round.','status'=>403,'noSave'=>true];
        foreach ($state['players'] as $p) {
            $connected=!empty($p['isBot']) || time()-(int)($p['lastSeen']??0)<PLAYER_TIMEOUT;
            if (empty($p['isBot']) && empty($p['ready']) && $connected) return ['error'=>'Every commander must mark ready.','status'=>409,'noSave'=>true];
        }
        $state['round']++; $state['phase']='playing'; newBattle($state); return ['ok'=>true];
    });
} elseif ($action==='rematch') {
    $mutation=mutateRoom($code,function(array &$room) use($me) {
        $state=&$room['state'];
        if (hostId($state)!==$me || ($state['phase']??'')!=='match_over') return ['error'=>'Only the host can order a rematch.','status'=>403,'noSave'=>true];
        foreach ($state['players'] as &$p) { $p['credits']=$state['settings']['startCredits']; $p['wins']=0; $p['kills']=0; $p['armor']=0; $p['inventory']=newInventory(); $p['ready']=false; }
        unset($p); $state['trailHistory']=[]; $state['round']=0; newBattle($state); $state['phase']='shop'; $state['currentPlayerId']=null;
        foreach ($state['players'] as &$p) if (!empty($p['isBot'])) cpuShop($p);
        unset($p); return ['ok'=>true];
    });
} else {
    jsonOut(['ok'=>false,'error'=>'Unknown action.'],400);
}

if (!$mutation['room']) jsonOut(['ok'=>false,'error'=>'Room not found.'],404);
if (!empty($mutation['result']['error'])) jsonOut(['ok'=>false,'error'=>$mutation['result']['error']],(int)($mutation['result']['status']??400));
jsonOut(['ok'=>true,'state'=>publicState($mutation['room'],$me)]+($mutation['result']??[]));
