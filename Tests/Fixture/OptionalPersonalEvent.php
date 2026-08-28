<?php

declare(strict_types=1);

namespace Storm\Serializer\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\Attribute\Personal;

/**
 * A marked class whose single declared key is optional end to end: the shape that can carry NONE
 * of its declared keys and still rebuild, which is what lets the suite prove the decorator skips
 * the key store when there is nothing to decrypt and nothing to redact.
 */
#[Personal(subject: 'customer_id', keys: ['nickname'], fallback: ['nickname' => null])]
final class OptionalPersonalEvent implements DomainEvent
{
    public function __construct(
        public string $customerId,
        public ?string $nickname = null,
    ) {}

    public function aggregateId(): string
    {
        return $this->customerId;
    }

    public function toPayload(): array
    {
        return ['customer_id' => $this->customerId, 'nickname' => $this->nickname];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((string) $payload['customer_id'], isset($payload['nickname']) ? (string) $payload['nickname'] : null);
    }
}
