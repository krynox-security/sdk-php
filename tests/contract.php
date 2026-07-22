<?php
declare(strict_types=1);

require __DIR__ . '/../src/KrynoxCaptcha.php';

use Krynox\Captcha\KrynoxResult;

$golden = json_decode(file_get_contents(__DIR__ . '/fixtures/golden-v1.json'), true, flags: JSON_THROW_ON_ERROR);
$verify = $golden['verify'];
$result = new KrynoxResult(
    $verify['success'], $verify['score'], $verify['risk'], $verify['hostname'],
    $verify['challenge_ts'], [], $verify['reasons'], null, null, $verify['action'], $verify['cdata']
);
assert($result->success === true);
assert($result->action === 'signup');
assert($result->cdata === 'order-42');
assert(in_array($golden['classify']['classification'], ['GOOD', 'NEUTRAL', 'BAD'], true));
echo "golden contract v1: ok\n";
