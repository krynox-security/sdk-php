<?php

declare(strict_types=1);

namespace Krynox\Captcha;

/**
 * Krynox Captcha — official server-side verification SDK (PHP).
 *
 *   $krynox = new \Krynox\Captcha\KrynoxCaptcha(getenv('KRYNOX_SECRET'));
 *   $result = $krynox->verify($_POST['krynox-captcha'] ?? '', $_SERVER['REMOTE_ADDR'] ?? null);
 *   if (!$result->success) { http_response_code(400); exit('captcha failed'); }
 *   if ($result->risk === 'high') {
 *       // add friction (email verification, manual review, ...)
 *   }
 */
final class KrynoxCaptcha
{
    private const DEFAULT_ENDPOINT = 'https://api.krynox.id/siteverify';

    private string $secret;
    private string $endpoint;
    private int $timeout;

    public function __construct(string $secret, string $endpoint = self::DEFAULT_ENDPOINT, int $timeout = 5)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('KrynoxCaptcha: secret key is required');
        }
        $this->secret = $secret;
        $this->endpoint = $endpoint;
        $this->timeout = $timeout;
    }

    public function verify(string $response, ?string $remoteip = null): KrynoxResult
    {
        if ($response === '') {
            return new KrynoxResult(false, null, null, null, null, ['missing-input-response']);
        }

        $payload = json_encode(['secret' => $this->secret, 'response' => $response, 'remoteip' => $remoteip]);

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $raw = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);

        if ($err !== 0 || $raw === false) {
            return new KrynoxResult(false, null, null, null, null, ['request-failed']);
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return new KrynoxResult(false, null, null, null, null, ['request-failed']);
        }

        return new KrynoxResult(
            ($data['success'] ?? false) === true,
            isset($data['score']) ? (float) $data['score'] : null,
            $data['risk'] ?? null,
            $data['hostname'] ?? null,
            $data['challenge_ts'] ?? null,
            is_array($data['error-codes'] ?? null) ? $data['error-codes'] : []
        );
    }

    /**
     * Report detection-quality feedback ('human' | 'bot'). Flagging an
     * auto-blocked IP as 'human' un-blocks it server-side (false-positive
     * correction). Returns ['ok' => bool, 'corrected' => bool].
     *
     * @return array{ok: bool, corrected: bool}
     */
    public function feedback(string $label, ?string $ip = null, ?string $note = null): array
    {
        $endpoint = preg_replace('#/siteverify$#', '/feedback', $this->endpoint);
        $payload = json_encode(['secret' => $this->secret, 'label' => $label, 'ip' => $ip, 'note' => $note]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $raw = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);

        if ($err !== 0 || $raw === false) {
            return ['ok' => false, 'corrected' => false];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'corrected' => false];
        }

        return ['ok' => ($data['ok'] ?? false) === true, 'corrected' => ($data['corrected'] ?? false) === true];
    }
}

final class KrynoxResult
{
    /** @param string[] $errorCodes */
    public function __construct(
        public readonly bool $success,
        public readonly ?float $score = null,
        public readonly ?string $risk = null,
        public readonly ?string $hostname = null,
        public readonly ?string $challengeTs = null,
        public readonly array $errorCodes = []
    ) {
    }
}
