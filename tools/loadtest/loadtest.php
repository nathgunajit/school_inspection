<?php
/**
 * Load driver (Modification V5 Step 2, see LOAD_TEST.md).
 *
 * Simulates N officers at the same moment, each with its own cookies and CSRF token, against the app
 * served by Apache. Needs the LoadTestSeeder data and LOADTEST_MODE=true (local only).
 *
 *   php tools/loadtest/loadtest.php --scenario=login --steps=10,25,50,100,200
 *   php tools/loadtest/loadtest.php --scenario=form  --steps=10,25,50,100 --base=http://localhost/SCHOOLINSPECTION/public
 *
 * Scenarios
 *   login : GET /login, POST /login, GET /dashboard
 *   form  : login, then start (or reopen) an inspection of the officer's own school, open step 1,
 *           10 auto-saves (POST /answers), open step 2, GET /progress
 *
 * Stops early when errors exceed 1 % or the 95th percentile exceeds --p95-limit seconds (default 3).
 */

$opt = getopt('', ['scenario::', 'steps::', 'base::', 'p95-limit::', 'db::', 'db-user::', 'db-pass::', 'out::', 'offset::']);
$scenario = $opt['scenario'] ?? 'login';
$steps = array_map('intval', explode(',', $opt['steps'] ?? '10,25,50,100,200'));
$base = rtrim($opt['base'] ?? 'http://localhost/SCHOOLINSPECTION/public', '/');
$p95Limit = (float) ($opt['p95-limit'] ?? 3);
$offset = (int) ($opt['offset'] ?? 0); // first officer number - 1 (to use fresh officers)
$pdo = new PDO('mysql:host=127.0.0.1;dbname='.($opt['db'] ?? 'schoolinspection'), $opt['db-user'] ?? 'root', $opt['db-pass'] ?? '');

const PASSWORD = 'Load@1234';
const MOBILE_BASE = 7000000000;

// School id of each officer's own "form school" (UDISE 19000000000 + n)
$schoolOf = $pdo->query("select udise_code, id from schools where udise_code like '19%'")->fetchAll(PDO::FETCH_KEY_PAIR);
if (! $schoolOf) {
    fwrite(STDERR, "No load-test data. Run: php artisan db:seed --class=LoadTestSeeder\n");
    exit(1);
}

$codes = ['BAS_PERSON_NAME' => 'Head Teacher', 'BAS_PERSON_DESIG' => 'Head Teacher', 'CND_01' => 'pucca', 'CND_02' => 'yes', 'CND_03' => 'no',
    'CND_04' => 'yes', 'CND_05' => 'yes', 'CND_06' => 'no', 'CND_07' => 'yes', 'CND_08' => 'yes'];

/** The request sequence of one virtual officer. Each step gets the previous response and returns the next request or null. */
function plan(string $scenario, int $n, string $base, array $schoolOf, array $codes): Generator
{
    $r = yield ['login page', 'GET', $base.'/login'];
    preg_match('/name="_token" value="([^"]+)"/', $r['body'], $m);
    $r = yield ['login post', 'POST', $base.'/login', ['_token' => $m[1] ?? '', 'mobile' => (string) (MOBILE_BASE + $n), 'password' => PASSWORD], 302];
    $r = yield ['dashboard', 'GET', $base.'/dashboard'];
    if ($scenario !== 'form') {
        return;
    }
    preg_match('/name="csrf-token" content="([^"]+)"/', $r['body'], $m);
    $token = $m[1] ?? '';
    $school = $schoolOf[(string) (19000000000 + $n)] ?? null;
    $r = yield ['start inspection', 'POST', $base.'/schools/'.$school.'/inspections', ['_token' => $token, 'lat' => '26.14', 'lng' => '91.73', 'accuracy' => '12'], 302];
    preg_match('#/inspections/(\d+)/step/1#', $r['location'] ?? '', $m);
    $insp = $m[1] ?? 0;
    yield ['step 1', 'GET', $base.'/inspections/'.$insp.'/step/1'];
    foreach ($codes as $code => $value) {
        yield ['auto-save answer', 'POST', $base.'/inspections/'.$insp.'/answers', ['code' => $code, 'value' => $value], 200, $token];
    }
    yield ['step 2', 'GET', $base.'/inspections/'.$insp.'/step/2'];
    yield ['progress', 'GET', $base.'/inspections/'.$insp.'/progress'];
}

function configure(CurlHandle $h, array $req): void
{
    [, $method, $url] = $req;
    $headers = ['Accept: text/html,application/json'];
    curl_setopt_array($h, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEFILE => '', CURLOPT_HTTPGET => true, CURLOPT_USERAGENT => 'SI-LoadTest/1.0']);
    if ($method === 'POST') {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($req[3] ?? []));
        if (isset($req[5])) { // AJAX auto-save, like the wizard
            $headers = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest', 'X-CSRF-TOKEN: '.$req[5]];
        }
    }
    curl_setopt($h, CURLOPT_HTTPHEADER, $headers);
}

function runStep(int $users, string $scenario, string $base, array $schoolOf, array $codes, int $offset): array
{
    $mh = curl_multi_init();
    $vus = [];
    $t0 = microtime(true);
    for ($i = 1; $i <= $users; $i++) {
        $gen = plan($scenario, $offset + $i, $base, $schoolOf, $codes);
        $h = curl_init();
        $vus[(int) spl_object_id($h)] = ['gen' => $gen, 'h' => $h, 'req' => $gen->current(), 'ok' => true];
        configure($h, $gen->current());
        curl_multi_add_handle($mh, $h);
    }
    $samples = [];
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.05);
        while ($info = curl_multi_info_read($mh)) {
            $h = $info['handle'];
            $vu = &$vus[(int) spl_object_id($h)];
            $raw = (string) curl_multi_getcontent($h);
            $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $hs = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
            preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $loc);
            $expect = $vu['req'][4] ?? 200;
            $ok = $info['result'] === CURLE_OK && $code === $expect;
            $samples[] = ['label' => $vu['req'][0], 'code' => $info['result'] === CURLE_OK ? $code : 'curl-'.$info['result'], 'ok' => $ok,
                't' => curl_getinfo($h, CURLINFO_TOTAL_TIME)];
            if (! $ok && getenv('LOADTEST_DEBUG')) {
                fwrite(STDERR, "--- {$vu['req'][0]} {$code}
".substr(strip_tags(substr($raw, $hs)), 0, 600)."
");
            }
            curl_multi_remove_handle($mh, $h);
            if (! $ok) { // a failed step ends this virtual officer
                curl_close($h);
                unset($vu);
                continue;
            }
            $vu['gen']->send(['code' => $code, 'body' => substr($raw, $hs), 'location' => $loc[1] ?? null]);
            if ($vu['gen']->valid()) {
                $vu['req'] = $vu['gen']->current();
                configure($h, $vu['req']);
                curl_multi_add_handle($mh, $h);
                $running = 1;
            } else {
                curl_close($h);
            }
            unset($vu);
        }
    } while ($running > 0);
    curl_multi_close($mh);

    return [$samples, microtime(true) - $t0];
}

function pct(array $v, float $p): float
{
    if (! $v) {
        return 0;
    }
    sort($v);

    return $v[(int) min(count($v) - 1, floor($p * (count($v) - 1) + 0.5))];
}

function dbStatus(PDO $pdo): array
{
    $s = $pdo->query("show global status where Variable_name in ('Threads_connected','Max_used_connections','Threads_running','Slow_queries','Innodb_row_lock_waits')")->fetchAll(PDO::FETCH_KEY_PAIR);

    return array_map('intval', $s);
}

$results = ['scenario' => $scenario, 'base' => $base, 'started' => date('c'), 'steps' => []];
printf("Scenario: %s   Target: %s\n", $scenario, $base);
printf("%6s | %6s | %7s | %8s | %8s | %8s | %8s | %6s | %s\n", 'Users', 'Reqs', 'Req/s', 'Avg ms', 'Med ms', 'P95 ms', 'Max ms', 'Err %', 'DB conn (max used) / slow');
echo str_repeat('-', 110), "\n";
$pdo->exec('flush status'); // resets Max_used_connections (session counters); harmless locally

// Warm-up: one officer walks the scenario first (not measured), so Apache compiles every Blade view once.
// On Windows, many threads compiling the same view at once collide on rename() (LOAD_TEST.md finding 2).
runStep(1, $scenario, $base, $schoolOf, $codes, $offset);

foreach ($steps as $users) {
    $before = dbStatus($pdo);
    [$samples, $secs] = runStep($users, $scenario, $base, $schoolOf, $codes, $offset);
    $after = dbStatus($pdo);
    $times = array_map(fn ($s) => $s['t'] * 1000, $samples);
    $errors = array_values(array_filter($samples, fn ($s) => ! $s['ok']));
    $errPct = $samples ? 100 * count($errors) / count($samples) : 100;
    $byLabel = [];
    foreach ($samples as $s) {
        $byLabel[$s['label']][] = $s['t'] * 1000;
    }
    $row = [
        'users' => $users, 'requests' => count($samples), 'seconds' => round($secs, 2), 'rps' => round(count($samples) / max($secs, 0.001), 1),
        'avg_ms' => round(array_sum($times) / max(1, count($times))), 'median_ms' => round(pct($times, .5)), 'p95_ms' => round(pct($times, .95)), 'max_ms' => round(max($times ?: [0])),
        'error_pct' => round($errPct, 2), 'errors' => array_count_values(array_map(fn ($e) => $e['label'].' → '.$e['code'], $errors)),
        'per_request' => array_map(fn ($v) => ['n' => count($v), 'median_ms' => round(pct($v, .5)), 'p95_ms' => round(pct($v, .95))], $byLabel),
        'db' => ['max_used_connections' => $after['Max_used_connections'] ?? null, 'slow_queries' => ($after['Slow_queries'] ?? 0) - ($before['Slow_queries'] ?? 0),
            'row_lock_waits' => ($after['Innodb_row_lock_waits'] ?? 0) - ($before['Innodb_row_lock_waits'] ?? 0)],
    ];
    $results['steps'][] = $row;
    printf("%6d | %6d | %7.1f | %8d | %8d | %8d | %8d | %6.2f | %d / %d\n", $users, $row['requests'], $row['rps'], $row['avg_ms'], $row['median_ms'],
        $row['p95_ms'], $row['max_ms'], $row['error_pct'], $row['db']['max_used_connections'], $row['db']['slow_queries']);
    foreach ($row['errors'] as $what => $count) {
        printf("         error: %s × %d\n", $what, $count);
    }
    if ($errPct > 1 || $row['p95_ms'] > $p95Limit * 1000) {
        printf("Stopped: %s at %d users.\n", $errPct > 1 ? 'errors above 1 %' : 'P95 above '.$p95Limit.' s', $users);
        break;
    }
    sleep(2);
}

$dir = __DIR__.'/../../storage/loadtest';
@mkdir($dir, 0777, true);
$file = $opt['out'] ?? $dir.'/results-'.$scenario.'-'.date('Ymd-His').'.json';
file_put_contents($file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "Saved: $file\n";
