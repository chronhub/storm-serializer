<?php

declare(strict_types=1);

namespace Storm\Serializer\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\Attribute\Personal;

/**
 * A marked domain event for the ciphering suite: a clear subject, a clear business amount, and two
 * personal keys whose fallbacks differ by design, a redaction glyph for one and a null for the
 * other.
 */
#[Personal(subject: 'customer_id', keys: ['full_name', 'email'], fallback: ['full_name' => '⌫', 'email' => null])]
final class PersonalSampleEvent implements DomainEvent
{
    public function __construct(
        public string $customerId,
        public string $fullName,
        public ?string $email,
        public int $amount,
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
            'email' => $this->email,
            'amount' => $this->amount,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            (string) $payload['customer_id'],
            (string) $payload['full_name'],
            isset($payload['email']) ? (string) $payload['email'] : null,
            (int) $payload['amount'],
        );
    }
}
