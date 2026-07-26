<?php

declare(strict_types=1);

/**
 * Pure-PHP mock data plane for the integration tests, served via the PHP
 * built-in web server:
 *
 *   KRYNOX_TEST_STATE=<dir> php -S 127.0.0.1:<port> tests/mock-server.php
 *
 * Every request is appended to <state>/requests.log as a JSON line
 * {"path": ..., "body": ..., "ua": ...} so the test can assert exact request
 * bodies, headers and hit counts. Stateful scenarios (retry endpoints) count
 * hits in files under the state dir.
 */

$stateDir = getenv('KRYNOX_TEST_STATE') ?: sys_get_temp_dir();
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = (string) file_get_contents('php://input');

file_put_contents(
    $stateDir . '/requests.log',
    json_encode(['path' => $path, 'body' => $body, 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null]) . "\n",
    FILE_APPEND | LOCK_EX
);

/** Increment and return the per-endpoint hit counter. */
function hits(string $stateDir, string $name): int
{
    $file = $stateDir . '/' . $name . '.count';
    $n = (is_file($file) ? (int) file_get_contents($file) : 0) + 1;
    file_put_contents($file, (string) $n);
    return $n;
}

/** @return array<string,mixed> */
function golden(string $section): array
{
    $golden = json_decode((string) file_get_contents(__DIR__ . '/fixtures/golden-v1.json'), true);
    return $golden[$section];
}

header('Content-Type: application/json');

switch ($path) {
    case '/siteverify': // happy path (and body-shape assertions)
        echo json_encode(golden('verify'));
        break;

    case '/siteverify-fail': // API-level failure with parseable error-codes
        echo json_encode(golden('error'));
        break;

    case '/retry-500': // transient 500, then success
        if (hits($stateDir, 'retry-500') === 1) {
            http_response_code(500);
            echo json_encode(['error' => 'boom']);
        } else {
            echo json_encode(golden('verify'));
        }
        break;

    case '/retry-429': // transient rate limit, then success
        if (hits($stateDir, 'retry-429') === 1) {
            http_response_code(429);
            echo json_encode(['error' => 'rate-limited']);
        } else {
            echo json_encode(golden('verify'));
        }
        break;

    case '/retry-exhaust': // always fails; non-JSON body so the SDK yields request-failed
        hits($stateDir, 'retry-exhaust');
        http_response_code(500);
        header('Content-Type: text/plain');
        echo 'boom';
        break;

    case '/slow': // slower than the smallest configurable CURLOPT_TIMEOUT (1 s)
        sleep(2);
        echo json_encode(golden('verify'));
        break;

    // Both derived-URL shapes: `<base>/siteverify` collapses to `<base>/classify`, while a plain
    // base endpoint (`/base`, with or without a trailing slash) appends to give `/base/classify`.
    case '/classify':
    case '/base/classify':
        echo json_encode(golden('classify'));
        break;

    case '/feedback':
    case '/base/feedback':
        echo json_encode(['ok' => true, 'corrected' => true]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'not-found']);
}
