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
if ($result->risk === 'high' || in_array('tor-exit', $result->reasons, true)) {
    // add friction
}
```

### Reasons, agents & attested humans

- `$result->reasons` — stable codes explaining the score (`'tor-exit'`, `'elevated-request-rate'`, …).
- `$result->agent` — a `KrynoxAgent` when a **verified AI agent** (Web Bot Auth) was forwarded:
  `->verified`, `->name`, `->allowlisted`. Allowlist good bots instead of blocking them.
- `$result->human` — a `KrynoxHuman` when a **device-attested human** (Private Access Token) was
  forwarded: `->attested`, `->method`, `->issuer`.

```php
if ($result->agent?->verified && $result->agent->allowlisted) { /* trusted crawler */ }
if ($result->human?->attested) { /* proven human, skip friction */ }
```

### Content classification (spam/abuse)

```php
$c = $krynox->classify($comment, null, $_SERVER['REMOTE_ADDR'] ?? null); // or a $fields array
if ($c->blocked || $c->classification === 'BAD') { http_response_code(400); exit('rejected'); }
```

### Reliability

Transient failures (network, `429`, `5xx`) are retried automatically (default **2**, exponential
backoff; 4th constructor arg `$retries`). A retried `verify` carries an **idempotency key** so it
never fails the single-use token — the server replays the first outcome.

### Feedback (false-positive correction)

Report detection quality back to Krynox. Flagging an auto-blocked IP as `human`
immediately un-blocks it server-side — a closed feedback loop that tunes detection.

```php
// a real user got blocked by mistake → un-block their IP
$fb = $krynox->feedback('human', $_SERVER['REMOTE_ADDR'] ?? null, 'support ticket #1234');
// $fb->ok === true, $fb->corrected === true

// confirm a bot you let through
$krynox->feedback('bot', $suspiciousIp);
```

### API
- `new KrynoxCaptcha(string $secret, string $endpoint = ..., int $timeout = 5, int $retries = 2)`
- `->verify(string $response, ?string $remoteip = null, ?string $idempotencyKey = null): KrynoxResult`
- `->classify(?string $text = null, ?array $fields = null, ?string $ip = null): KrynoxClassification`
- `->feedback(string $label, ?string $ip = null, ?string $note = null): KrynoxFeedback` — `$label` is `'human'` or `'bot'`

`KrynoxResult`: `success, score, risk, hostname, challengeTs, action, cdata, errorCodes, reasons, agent, human`.
`KrynoxClassification`: `ok, score, classification, reasons, blocked, errorCodes`.
Error codes: `KrynoxErrorCode::RATE_LIMITED`, etc.

Self-hosting? Pass the endpoint as the 2nd constructor arg.
