# krynox/captcha (PHP)

Official server-side verification SDK for **Krynox Captcha**.

```bash
composer require krynox/captcha
```

```php
use Krynox\Captcha\KrynoxCaptcha;

$krynox = new KrynoxCaptcha(getenv('KRYNOX_SECRET'));
$result = $krynox->verify($_POST['krynox-captcha'] ?? '', $_SERVER['REMOTE_ADDR'] ?? null);

if (!$result->success) {
    http_response_code(400);
    exit('Captcha verification failed');
}
if ($result->risk === 'high') {
    // add friction
}
```

### Feedback (false-positive correction)

Report detection quality back to Krynox. Flagging an auto-blocked IP as `human`
immediately un-blocks it server-side — a closed feedback loop that tunes detection.

```php
// a real user got blocked by mistake → un-block their IP
$fb = $krynox->feedback('human', $_SERVER['REMOTE_ADDR'] ?? null, 'support ticket #1234');
// $fb === ['ok' => true, 'corrected' => true]

// confirm a bot you let through
$krynox->feedback('bot', $suspiciousIp);
```

### API
- `new KrynoxCaptcha(string $secret, string $endpoint = ..., int $timeout = 5)`
- `->verify(string $response, ?string $remoteip = null): KrynoxResult`
- `->feedback(string $label, ?string $ip = null, ?string $note = null): array` — `$label` is `'human'` or `'bot'`; returns `['ok' => bool, 'corrected' => bool]`

`KrynoxResult`: `success, score, risk, hostname, challengeTs, errorCodes`.

Self-hosting? Pass the endpoint as the 2nd constructor arg.
