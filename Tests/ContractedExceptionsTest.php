<?php

declare(strict_types=1);

namespace Storm\Serializer\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Serializer\Exception\SerializationException;

/**
 * The exception seam of the Serializer port: the concrete serialization or deserialization failure is
 * catchable by the Contracts-owned high-level type, so a caller such as the aggregate repository stays
 * decoupled from the Serializer package. Break `implements SerializationExceptionContract` and this fails.
 */
final class ContractedExceptionsTest extends TestCase
{
    #[Test]
    public function a_serialization_failure_is_catchable_by_the_contracted_type(): void
    {
        $failure = SerializationException::cannotDeserialize('order.placed');

        $this->assertInstanceOf(SerializationExceptionContract::class, $failure);
        $this->assertInstanceOf(RuntimeException::class, $failure); // the SPL intent axis kept
    }
}
