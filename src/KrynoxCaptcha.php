<?php

declare(strict_types=1);

namespace Krynox\Captcha;

/**
 * Krynox Captcha — official server-side verification SDK (PHP).
 *
 *   $krynox = new \Krynox\Captcha\KrynoxCaptcha(getenv('KRYNOX_SECRET'));
 *   $result = $krynox->verify($_POST['krynox-captcha'] ?? '', $_SERVER['REMOTE_ADDR'] ?? null);
 *   if (!$result->success) { http_response_code(400); exit('captcha failed'); }
 *   if ($result->risk === 'high' || in_array('tor-exit', $result->reasons, true)) {
 *       // add friction (email verification, manual review, ...)
 *   }
 */
final class KrynoxCaptcha
{
    private const DEFAULT_ENDPOINT = 'https://api.krynox.net/siteverify';

    /**
     * Package version. Composer packages are versioned by the release tag rather than by
     * composer.json, so this constant is the single source of truth for the wire version —
     * bump it in the same commit as the `v…` tag.
     */
    public const VERSION = '0.1.0';

    /** Sent as `User-Agent` on every request, so the API can attribute traffic to SDK + version. */
    public const USER_AGENT = 'krynox-captcha-php/' . self::VERSION;

    private string $secret;
    private string $endpoint;
    private int $timeout;
    private int $retries;

    public function __construct(
        string $secret,
        string $endpoint = self::DEFAULT_ENDPOINT,
        int $timeout = 5,
        int $retries = 2
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('KrynoxCaptcha: secret key is required');
        }
        $this->secret = $secret;
        $this->endpoint = $endpoint;
        $this->timeout = $timeout;
        $this->retries = $retries;
    }

    /** Verify a captcha response token from the widget. */
    public function verify(string $response, ?string $remoteip = null, ?string $idempotencyKey = null): KrynoxResult
    {
        if ($response === '') {
            return new KrynoxResult(false, null, null, null, null, [KrynoxErrorCode::MISSING_RESPONSE]);
        }

        // A token is single-use, so a retried verify carries an idempotency key — the server returns
        // the first outcome instead of failing the now-consumed token.
        $key = $idempotencyKey ?? ($this->retries > 0 ? bin2hex(random_bytes(16)) : null);
        $data = $this->post($this->endpoint, [
            'secret' => $this->secret,
            'response' => $response,
            'remoteip' => $remoteip,
            'idempotency_key' => $key,
        ]);
        if ($data === null) {
            return new KrynoxResult(false, null, null, null, null, [KrynoxErrorCode::REQUEST_FAILED]);
        }

        $agent = is_array($data['agent'] ?? null) ? $data['agent'] : null;
        $human = is_array($data['human'] ?? null) ? $data['human'] : null;

        return new KrynoxResult(
            ($data['success'] ?? false) === true,
            isset($data['score']) ? (float) $data['score'] : null,
            $data['risk'] ?? null,
            $data['hostname'] ?? null,
            $data['challenge_ts'] ?? null,
            is_array($data['error-codes'] ?? null) ? $data['error-codes'] : [],
            is_array($data['reasons'] ?? null) ? $data['reasons'] : [],
            $agent !== null ? new KrynoxAgent(
                ($agent['verified'] ?? false) === true,
                $agent['name'] ?? null,
                ($agent['allowlisted'] ?? false) === true
            ) : null,
            $human !== null ? new KrynoxHuman(
                ($human['attested'] ?? false) === true,
                $human['method'] ?? null,
                $human['issuer'] ?? null
            ) : null,
            $data['action'] ?? null,
            $data['cdata'] ?? null
        );
    }

    /**
     * Report detection-quality feedback ('human' | 'bot'). Flagging an auto-blocked IP as 'human'
     * un-blocks it server-side (false-positive correction).
     */
    public function feedback(string $label, ?string $ip = null, ?string $note = null): KrynoxFeedback
    {
        $data = $this->post($this->derive('/feedback'), [
            'secret' => $this->secret,
            'label' => $label,
            'ip' => $ip,
            'note' => $note,
        ]);
        if ($data === null) {
            return new KrynoxFeedback(false, false);
        }

        return new KrynoxFeedback(($data['ok'] ?? false) === true, ($data['corrected'] ?? false) === true);
    }

    /**
     * Score submitted content (a string via $text, or a $fields map) for spam/abuse.
     *
     * @param array<string,mixed>|null $fields
     */
    public function classify(?string $text = null, ?array $fields = null, ?string $ip = null): KrynoxClassification
    {
        $data = $this->post($this->derive('/classify'), [
            'secret' => $this->secret,
            'text' => $text,
            'fields' => $fields,
            'ip' => $ip,
        ]);
        if ($data === null) {
            return new KrynoxClassification(false, null, null, [], false, [KrynoxErrorCode::REQUEST_FAILED]);
        }

        return new KrynoxClassification(
            ($data['ok'] ?? false) === true,
            isset($data['score']) ? (float) $data['score'] : null,
            $data['classification'] ?? null,
            is_array($data['reasons'] ?? null) ? $data['reasons'] : [],
            ($data['blocked'] ?? false) === true,
            is_array($data['error-codes'] ?? null) ? $data['error-codes'] : []
        );
    }

    /**
     * Derive a sibling endpoint ('/classify', '/feedback') from the configured verify endpoint.
     * An endpoint ending in `/siteverify` (a trailing slash is ignored) has that suffix replaced;
     * anything else is treated as a base URL and the path is appended.
     */
    private function derive(string $path): string
    {
        $base = rtrim($this->endpoint, '/');
        if (str_ends_with($base, '/siteverify')) {
            $base = substr($base, 0, -strlen('/siteverify'));
        }

        return $base . $path;
    }

    /**
     * POST JSON, retrying transient failures (network / 429 / 5xx). Returns the decoded array, or
     * null on final failure.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>|null
     */
    private function post(string $url, array $body): ?array
    {
        $payload = json_encode($body);
        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: ' . self::USER_AGENT],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
            ]);
            $raw = curl_exec($ch);
            $err = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            // curl_close() is a no-op since PHP 8.0 (deprecated in 8.5); the handle frees on scope exit.

            $transient = $err !== 0 || $raw === false || $status === 429 || $status >= 500;
            if ($transient && $attempt < $this->retries) {
                usleep((int) (min(1.0, 0.1 * (2 ** $attempt)) * 1_000_000));
                continue;
            }
            if ($err !== 0 || $raw === false) {
                return null;
            }

            $data = json_decode((string) $raw, true);
            return is_array($data) ? $data : null;
        }

        return null;
    }
}

/** Machine-readable error codes returned by the API + SDK transport. */
final class KrynoxErrorCode
{
    public const MISSING_RESPONSE = 'missing-input-response';
    public const INVALID_RESPONSE = 'invalid-input-response';
    public const INVALID_SECRET = 'invalid-input-secret';
    public const RATE_LIMITED = 'rate-limited';
    public const TIMEOUT = 'timeout';
    public const REQUEST_FAILED = 'request-failed';
}

/** A cryptographically verified AI agent (Web Bot Auth), when forwarded. */
final class KrynoxAgent
{
    public function __construct(
        public readonly bool $verified,
        public readonly ?string $name = null,
        public readonly bool $allowlisted = false
    ) {
    }
}

/** A device-attested real human (Private Access Token), when forwarded. */
final class KrynoxHuman
{
    public function __construct(
        public readonly bool $attested,
        public readonly ?string $method = null,
        public readonly ?string $issuer = null
    ) {
    }
}

final class KrynoxResult
{
    /**
     * @param string[] $errorCodes
     * @param string[] $reasons
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?float $score = null,
        public readonly ?string $risk = null,
        public readonly ?string $hostname = null,
        public readonly ?string $challengeTs = null,
        public readonly array $errorCodes = [],
        public readonly array $reasons = [],
        public readonly ?KrynoxAgent $agent = null,
        public readonly ?KrynoxHuman $human = null,
        public readonly ?string $action = null,
        public readonly ?string $cdata = null
    ) {
    }
}

final class KrynoxFeedback
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $corrected = false
    ) {
    }
}

final class KrynoxClassification
{
    /**
     * @param string[] $reasons
     * @param string[] $errorCodes
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?float $score = null,
        public readonly ?string $classification = null,
        public readonly array $reasons = [],
        public readonly bool $blocked = false,
        public readonly array $errorCodes = []
    ) {
    }
}
