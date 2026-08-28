<?php

declare(strict_types=1);

namespace Storm\Serializer\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\Attribute\Personal;

/**
 * A deliberately drifted declaration: `email` is declared personal but `toPayload()` no longer
 * emits it; the write gate under test must refuse rather than silently store the drift in clear.
 */
#[Personal(subject: 'customer_id', keys: ['full_name', 'email'], fallback: ['full_name' => '⌫', 'email' => null])]
final class DriftingPersonalEvent implements DomainEvent
{
    public function __construct(
        public string $customerId,
        public string $fullName,
    ) {}

    public function aggregateId(): string
    {
        return $this->customerId;
    }

    public function toPayload(): array
    {
        return [
            'customer_id' => $this->customerId,
            'full_name' => $this->fullName,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((string) $payload['customer_id'], (string) $payload['full_name']);
    }
}
