<?php

declare(strict_types=1);

/**
 * Integration tests — the real SDK over real HTTP against a pure-PHP mock data
 * plane (`php -S` + tests/mock-server.php, spawned via proc_open). Runs after
 * tests/contract.php in CI:
 *
 *   php -d zend.assertions=1 -d assert.exception=1 tests/integration.php
 *
 * Scenarios: happy path (exact body keys incl. explicit null remoteip),
 * 500→200 retry, 429→200 retry, exhausted retries, timeout, API failure
 * parsing, classify()/feedback(), and "honeypot"/"sitekey" never sent.
 */

require __DIR__ . '/../src/KrynoxCaptcha.php';

use Krynox\Captcha\KrynoxCaptcha;

// --- boot the mock data plane -------------------------------------------------

$host = '127.0.0.1';
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($sock === false) {
    fwrite(STDERR, "integration: cannot allocate a port: $errstr\n");
    exit(1);
}
$name = (string) stream_socket_get_name($sock, false);
$port = (int) substr($name, (int) strrpos($name, ':') + 1);
fclose($sock);

$stateDir = sys_get_temp_dir() . '/krynox-php-it-' . getmypid();
if (!is_dir($stateDir) && !mkdir($stateDir, 0777, true)) {
    fwrite(STDERR, "integration: cannot create state dir $stateDir\n");
    exit(1);
}

$proc = proc_open(
    [PHP_BINARY, '-S', "$host:$port", __DIR__ . '/mock-server.php'],
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', "$stateDir/server.log", 'w'],
        2 => ['file', "$stateDir/server.log", 'w'],
    ],
    $pipes,
    null,
    ['KRYNOX_TEST_STATE' => $stateDir] + getenv()
);
if (!is_resource($proc)) {
    fwrite(STDERR, "integration: failed to spawn mock server\n");
    exit(1);
}
register_shutdown_function(function () use (&$proc): void {
    if (is_resource($proc)) {
        @proc_terminate($proc);
    }
});

// Wait until the server accepts connections.
$ready = false;
$deadline = microtime(true) + 10.0;
while (microtime(true) < $deadline) {
    $conn = @fsockopen($host, $port, $eno, $estr, 0.25);
    if ($conn !== false) {
        fclose($conn);
        $ready = true;
        break;
    }
    usleep(50_000);
}
assert($ready === true, 'mock server became ready');

/**
 * Requests recorded by the mock server, optionally filtered by path.
 *
 * @return array<int,array{path:string,body:string}>
 */
function recorded(string $stateDir, ?string $path = null): array
{
    $file = $stateDir . '/requests.log';
    if (!is_file($file)) {
        return [];
    }
    $reqs = array_map(
        static fn (string $line): array => json_decode($line, true),
        file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
    );
    return $path === null
        ? $reqs
        : array_values(array_filter($reqs, static fn (array $r): bool => $r['path'] === $path));
}

$secret = 'kcps_test_secret';
$sdk = new KrynoxCaptcha($secret, "http://$host:$port/siteverify", 2, 2);

// --- 1. happy path: golden fixture parsing + exact body keys -------------------

$r = $sdk->verify('test-token');
assert($r->success === true, 'happy: success');
assert($r->score === 0.91, 'happy: score');
assert($r->risk === 'low', 'happy: risk');
assert($r->hostname === 'app.example.com', 'happy: hostname');
assert($r->challengeTs === '2026-07-19T00:00:00.000Z', 'happy: challenge_ts');
assert($r->reasons === ['verified-agent'], 'happy: reasons');
assert($r->errorCodes === [], 'happy: no error codes');
assert($r->action === 'signup', 'happy: action');
assert($r->cdata === 'order-42', 'happy: cdata');
assert(
    $r->agent !== null && $r->agent->verified === true
        && $r->agent->name === 'ExampleBot' && $r->agent->allowlisted === true,
    'happy: agent'
);
assert(
    $r->human !== null && $r->human->attested === true
        && $r->human->method === 'passkey' && $r->human->issuer === null,
    'happy: human'
);

$reqs = recorded($stateDir, '/siteverify');
assert(count($reqs) === 1, 'happy: exactly one hit');
$body = json_decode($reqs[0]['body'], true);
assert(array_keys($body) === ['secret', 'response', 'remoteip', 'idempotency_key'], 'happy: exact body keys');
assert($body['secret'] === $secret, 'happy: secret forwarded');
assert($body['response'] === 'test-token', 'happy: response forwarded');
assert($body['remoteip'] === null, 'happy: explicit null remoteip when absent');
assert(preg_match('/^[0-9a-f]{32}$/', (string) $body['idempotency_key']) === 1, 'happy: 16-byte hex idempotency key');

// 1b. remoteip forwarded when provided
$sdk->verify('test-token', '203.0.113.9');
$reqs = recorded($stateDir, '/siteverify');
assert(count($reqs) === 2, 'remoteip: second hit');
$body = json_decode($reqs[1]['body'], true);
assert($body['remoteip'] === '203.0.113.9', 'remoteip: value forwarded');

// --- 2. 500-then-200: exactly 2 hits, same idempotency key ---------------------

$retry = new KrynoxCaptcha($secret, "http://$host:$port/retry-500", 2, 2);
$r = $retry->verify('tok');
assert($r->success === true && $r->score === 0.91, 'retry500: success after retry');
$reqs = recorded($stateDir, '/retry-500');
assert(count($reqs) === 2, 'retry500: exactly two hits');
$k1 = json_decode($reqs[0]['body'], true)['idempotency_key'];
$k2 = json_decode($reqs[1]['body'], true)['idempotency_key'];
assert(is_string($k1) && $k1 === $k2, 'retry500: same idempotency key on both attempts');
assert(preg_match('/^[0-9a-f]{32}$/', $k1) === 1, 'retry500: hex idempotency key');

// --- 3. 429-then-200 -----------------------------------------------------------

$retry = new KrynoxCaptcha($secret, "http://$host:$port/retry-429", 2, 2);
$r = $retry->verify('tok');
assert($r->success === true && $r->score === 0.91, 'retry429: success after retry');
$reqs = recorded($stateDir, '/retry-429');
assert(count($reqs) === 2, 'retry429: exactly two hits');
$k1 = json_decode($reqs[0]['body'], true)['idempotency_key'];
$k2 = json_decode($reqs[1]['body'], true)['idempotency_key'];
assert(is_string($k1) && $k1 === $k2, 'retry429: same idempotency key on both attempts');

// --- 4. exhausted retries → request-failed -------------------------------------

$exhaust = new KrynoxCaptcha($secret, "http://$host:$port/retry-exhaust", 2, 2);
$r = $exhaust->verify('tok');
assert($r->success === false && $r->errorCodes === ['request-failed'], 'exhaust: request-failed');
assert(count(recorded($stateDir, '/retry-exhaust')) === 3, 'exhaust: exactly three hits (2 retries)');

// --- 6. API failure body parsing -----------------------------------------------

$fail = new KrynoxCaptcha($secret, "http://$host:$port/siteverify-fail", 2, 2);
$r = $fail->verify('tok');
assert($r->success === false, 'fail: not success');
assert($r->errorCodes === ['invalid-input-response'], 'fail: error-codes parsed');
assert($r->reasons === [], 'fail: no reasons');

// --- 7. classify() / feedback() on the derived endpoints -----------------------

$c = $sdk->classify('cheap pills, buy now', null, '203.0.113.9');
assert(
    $c->ok === true && $c->score === 0.55 && $c->classification === 'NEUTRAL'
        && $c->reasons === ['risky-ip'] && $c->blocked === false,
    'classify: golden parse'
);
$reqs = recorded($stateDir, '/classify');
assert(count($reqs) === 1, 'classify: /classify hit');
$body = json_decode($reqs[0]['body'], true);
assert(array_keys($body) === ['secret', 'text', 'fields', 'ip'], 'classify: exact body keys');
assert($body['text'] === 'cheap pills, buy now' && $body['fields'] === null && $body['ip'] === '203.0.113.9', 'classify: values');

$f = $sdk->feedback('human', '203.0.113.9');
assert($f->ok === true && $f->corrected === true, 'feedback: ok + corrected');
$reqs = recorded($stateDir, '/feedback');
assert(count($reqs) === 1, 'feedback: /feedback hit');
$body = json_decode($reqs[0]['body'], true);
assert(array_keys($body) === ['secret', 'label', 'ip', 'note'], 'feedback: exact body keys');
assert($body['label'] === 'human' && $body['ip'] === '203.0.113.9' && $body['note'] === null, 'feedback: values');

// --- 5. timeout (1 s is the smallest configurable CURLOPT_TIMEOUT); kept last --

$slow = new KrynoxCaptcha($secret, "http://$host:$port/slow", 1, 0);
$t0 = microtime(true);
$r = $slow->verify('tok');
$elapsed = microtime(true) - $t0;
assert($r->success === false && $r->errorCodes === ['request-failed'], 'timeout: request-failed');
assert($elapsed < 1.8, 'timeout: cut off by CURLOPT_TIMEOUT, not the 2 s slow handler');

// --- 8. "honeypot" (and "sitekey") never sent ----------------------------------

$all = recorded($stateDir);
assert(count($all) >= 10, 'sanity: requests were recorded');
foreach ($all as $req) {
    assert(strpos($req['body'], 'honeypot') === false, 'no honeypot field ever sent');
    assert(strpos($req['body'], 'sitekey') === false, 'no sitekey field ever sent');
}

proc_terminate($proc);
proc_close($proc);
$proc = null;
echo "integration: ok (8 scenarios)\n";
