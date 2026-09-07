<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Gateways;

/**
 * One academy's credentials for one provider, already decrypted.
 *
 * A value object rather than the model, so a gateway implementation cannot
 * reach the rest of the row, cannot save it, and cannot accidentally serialise
 * it into a log line or an exception payload.
 */
final readonly class GatewayAccount
{
    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(
        public array $credentials,
        public string $webhookSecret,
        public bool $isTestMode,
    ) {}

    public function credential(string $key): string
    {
        return $this->credentials[$key] ?? '';
    }

    /**
     * Never let these reach a log, a stack trace or a debug dump.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['credentials' => '[redacted]', 'webhookSecret' => '[redacted]'];
    }
}
