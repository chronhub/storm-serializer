<?php

declare(strict_types=1);

namespace Storm\Serializer\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;
use Storm\Serializer\Exception\SerializationException;

/**
 * A domain event whose `fromPayload()` throws the codec's OWN contracted failure, so a test can
 * prove the serializer RE-THROWS it untouched rather than double-wrapping it under a redundant
 * cause. The deserialize twin of {@see ThrowingPayload}, which raises a plain non-Serializer
 * exception.
 */
final class ContractThrowingPayload implements DomainEvent
{
    public function __construct(public int $amount = 0) {}

    public function aggregateId(): string
    {
        return 'x';
    }

    public function toPayload(): array
    {
        return ['amount' => $this->amount];
    }

    public static function fromPayload(array $payload): static
    {
        throw SerializationException::missingField('amount');
    }
}
