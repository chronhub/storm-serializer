<?php

declare(strict_types=1);

namespace Storm\Serializer\Tests\Fixture;

use InvalidArgumentException;
use Storm\Contracts\Message\DomainEvent;

/**
 * A domain event whose codec is strict: toPayload / fromPayload raise a plain non-Serializer
 * exception on bad input, so a test can prove the serializer wraps it into the codec's own
 * SerializationExceptionContract rather than letting it escape raw.
 */
final class ThrowingPayload implements DomainEvent
{
    public function __construct(public int $amount = 0) {}

    public function aggregateId(): string
    {
        return 'x';
    }

    public function toPayload(): array
    {
        if ($this->amount < 0) {
            throw new InvalidArgumentException('amount must not be negative');
        }

        return ['amount' => $this->amount];
    }

    public static function fromPayload(array $payload): static
    {
        if (! isset($payload['amount']) || ! is_int($payload['amount'])) {
            throw new InvalidArgumentException('amount must be a present int');
        }

        return new self($payload['amount']);
    }
}
