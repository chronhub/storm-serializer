<?php

declare(strict_types=1);

namespace Storm\Serializer\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\Attribute\Personal;

/**
 * A marked class whose TWO declared keys are each optional at rebuild time: the shape that lets
 * the suite prove the read path walks EVERY declared key even when an earlier one is absent from
 * an older row, instead of stopping at the first gap.
 */
#[Personal(subject: 'customer_id', keys: ['nickname', 'email'], fallback: ['nickname' => '⌫', 'email' => null])]
final class SparsePersonalEvent implements DomainEvent
{
    public function __construct(
        public string $customerId,
        public ?string $nickname = null,
        public ?string $email = null,
    ) {}

    public function aggregateId(): string
    {
        return $this->customerId;
    }

    public function toPayload(): array
    {
        return ['customer_id' => $this->customerId, 'nickname' => $this->nickname, 'email' => $this->email];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            (string) $payload['customer_id'],
            isset($payload['nickname']) ? (string) $payload['nickname'] : null,
            isset($payload['email']) ? (string) $payload['email'] : null,
        );
    }
}
