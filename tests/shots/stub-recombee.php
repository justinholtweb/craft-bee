<?php
/**
 * A stand-in for the Recombee REST API, so the plugin-testing install can be driven with Bee's own
 * client code — real signing, real timing, real retries, real error handling — without a live
 * database. Started by seed-log.php; not part of the test suite.
 *
 *     php -S 127.0.0.1:8899 stub-recombee.php
 */
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'];
$raw    = file_get_contents('php://input');
$body   = $raw ? json_decode($raw, true) : null;

// Recombee is a hosted service on the other side of a continent; a request costs real time.
usleep(random_int(18_000, 95_000));

function send(int $code, mixed $payload): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo is_string($payload) ? json_encode($payload) : json_encode($payload);
    exit;
}

$rel = preg_replace('#^/[^/]+/#', '', $path);     // strip the database segment

// FLAKY=n makes n% of every call fail, so the connection log gets real error rows to show.
$flaky = (int)(getenv('FLAKY') ?: 0);
if ($flaky && random_int(1, 100) <= $flaky) {
    [$code, $msg] = [[429, 'Too many requests.'], [503, 'Service temporarily unavailable.'],
                     [400, 'Invalid value for property "price": expected double.']][random_int(0, 2)];
    send($code, $msg);
}

// Interactions are keyed on user + item + timestamp: a repeat is a 409, not a failure.
if (preg_match('#^(detailviews|purchases|cartadditions|ratings|bookmarks)/#', $rel)) {
    if (random_int(1, 100) <= (int)(getenv('DUPES') ?: 0)) {
        send(409, 'Interaction already exists.');
    }
    send(200, 'ok');
}

if (str_starts_with($rel, 'batch')) {
    $out = [];
    foreach ($body['requests'] ?? [] as $i => $req) {
        $dup = str_contains($req['path'] ?? '', 'purchases') && random_int(1, 100) <= 5;
        $out[] = $dup
            ? ['code' => 409, 'json' => 'Interaction already exists.']
            : ['code' => 200, 'json' => 'ok'];
    }
    send(200, $out);
}

// Properties are created by PUT and listed back, so Bee's "not defined yet" check behaves.
$store = sys_get_temp_dir() . '/stub-props.json';
if ($method === 'PUT' && preg_match('#^items/properties/([^/?]+)#', $rel, $m)) {
    $props = json_decode(@file_get_contents($store) ?: '{}', true) ?: [];
    $props[urldecode($m[1])] = $body['type'] ?? ($_GET['type'] ?? 'string');
    file_put_contents($store, json_encode($props));
    send(200, 'ok');
}
if (str_contains($rel, 'items/properties/list')) {
    $props = json_decode(@file_get_contents($store) ?: '{}', true) ?: [];
    $out = [];
    foreach ($props as $name => $type) {
        $out[] = ['name' => $name, 'type' => $type];
    }
    send(200, $out);
}

if (str_contains($rel, 'recomms/') || str_contains($rel, 'search/')) {
    $ids = json_decode(@file_get_contents(__DIR__ . '/stub-items.json') ?: '[]', true) ?: [];
    shuffle($ids);
    $n = (int)($body['count'] ?? 6);
    send(200, [
        'recommId' => bin2hex(random_bytes(8)) . '-' . bin2hex(random_bytes(4)),
        'recomms'  => array_map(
            static fn($id) => ['id' => $id, 'values' => new stdClass()],
            array_slice($ids, 0, $n),
        ),
        'numberNextRecommsCalls' => 0,
    ]);
}

// A hosted API is not always up. Rare, but Bee's retry ladder and log should show it.
$r = random_int(1, 1000);
if ($r <= 8)  send(429, 'Too many requests.');
if ($r <= 12) send(503, 'Service temporarily unavailable.');

send(200, 'ok');
