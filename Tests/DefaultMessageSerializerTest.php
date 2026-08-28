<?php

declare(strict_types=1);

namespace Storm\Serializer\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\DefaultMessageSerializer;
use Storm\Serializer\Exception\SerializationException;
use Storm\Serializer\Tests\Fixture\ContractThrowingPayload;
use Storm\Serializer\Tests\Fixture\SampleEvent;
use Storm\Serializer\Tests\Fixture\ThrowingPayload;

final class DefaultMessageSerializerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // serialize
    // -------------------------------------------------------------------------

    #[Test]
    public function serialize_produces_header_and_content(): void
    {
        $message = new Message(new SampleEvent('order-1', 50))
            ->withHeader(Header::MessageType, SampleEvent::class)
            ->withHeader(Header::MessageId, 'evt-1');

        $data = (new DefaultMessageSerializer)->serialize($message);

        $this->assertSame(['orderId' => 'order-1', 'amount' => 50], $data['content']);
        $this->assertSame(SampleEvent::class, $data['header'][Header::MessageType->value]);
        $this->assertSame('evt-1', $data['header'][Header::MessageId->value]);
    }

    #[Test]
    public function a_message_without_the_explicit_pair_fails_fast_with_the_remedy(): void
    {
        // the wire is explicit-only: no SerializablePayload means refuse at the boundary,
        // remedy in the message, never an accidental reflected shape
        try {
            (new DefaultMessageSerializer)->serialize(new Message(new stdClass));
            $this->fail('a message without SerializablePayload must fail fast');
        } catch (SerializationException $e) {
            $this->assertStringContainsString(stdClass::class, $e->getMessage());
            $this->assertStringContainsString('SerializablePayload', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // deserialize
    // -------------------------------------------------------------------------

    #[Test]
    public function deserialize_rebuilds_the_message(): void
    {
        $data = [
            'header' => [
                Header::MessageType->value => SampleEvent::class,
                Header::MessageId->value => 'evt-1',
            ],
            'content' => ['orderId' => 'order-1', 'amount' => 50],
        ];

        $message = (new DefaultMessageSerializer)->deserialize($data);

        $event = $message->message();
        $this->assertInstanceOf(SampleEvent::class, $event);
        $this->assertSame('order-1', $event->orderId);
        $this->assertSame(50, $event->amount);
        $this->assertSame('evt-1', $message->messageId());
    }

    #[Test]
    public function throws_when_type_is_unknown(): void
    {
        $this->expectException(SerializationException::class);

        (new DefaultMessageSerializer)->deserialize([
            'header' => [Header::MessageType->value => 'Not\\A\\Class'],
            'content' => [],
        ]);
    }

    #[Test]
    public function throws_when_type_header_is_missing(): void
    {
        $this->expectException(SerializationException::class);

        (new DefaultMessageSerializer)->deserialize(['header' => [], 'content' => []]);
    }

    #[Test]
    public function throws_when_the_type_is_loadable_but_not_a_serializable_payload(): void
    {
        // the deserialize mirror of the explicit-only rule: a loadable class without the pair has
        // no fromPayload() to feed; refuse with the same actionable remedy, not a fatal on the call
        try {
            (new DefaultMessageSerializer)->deserialize([
                'header' => [Header::MessageType->value => stdClass::class],
                'content' => [],
            ]);
            $this->fail('a non-SerializablePayload type must be rejected');
        } catch (SerializationException $e) {
            $this->assertStringContainsString('SerializablePayload', $e->getMessage());
        }
    }

    #[Test]
    public function a_declared_type_that_disagrees_names_the_declaration_in_the_refusal(): void
    {
        // the remedy in the message: the operator reading the refusal must see WHAT the header
        // declared, not the php type of the declaration
        $message = new Message(new SampleEvent('order-1', 50))
            ->withHeader(Header::MessageType, 'App\\Declared\\Elsewhere')
            ->withHeader(Header::MessageId, 'evt-1');

        try {
            (new DefaultMessageSerializer)->serialize($message);
            $this->fail('a declared MessageType that disagrees with the wrapped class must refuse');
        } catch (SerializationException $e) {
            $this->assertStringContainsString('[App\\Declared\\Elsewhere]', $e->getMessage());
            $this->assertStringContainsString(SampleEvent::class, $e->getMessage());
        }
    }

    #[Test]
    public function throws_a_clean_error_when_the_header_key_is_absent(): void
    {
        // belt-and-suspenders for a caller that bypasses the SerializedMessage factories: a missing `header`
        // key fails as a clean missingField SerializationException, not a raw undefined-key warning + a
        // TypeError out of assertWellTyped(null). Distinct from the empty-header case above.
        $this->expectException(SerializationException::class);

        // @phpstan-ignore argument.type (the @param shape requires `header`; this exercises the runtime guard)
        (new DefaultMessageSerializer)->deserialize(['content' => []]);
    }

    #[Test]
    public function throws_a_clean_error_when_the_content_key_is_absent(): void
    {
        // the `content` twin of the header guard: same belt-and-suspenders, same clean failure,
        // not an undefined-key warning + a TypeError out of fromPayload(null). The MESSAGE is the
        // assertion that counts: without the throw the exception OBJECT becomes the content and the
        // payload gate downstream wraps the wreck into a SerializationException of its own; the
        // type alone cannot tell the guard from the wreckage
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageMatches('/\[content\] field is missing/');

        // @phpstan-ignore argument.type (the @param shape requires `content`; this exercises the runtime guard)
        (new DefaultMessageSerializer)->deserialize([
            'header' => [Header::MessageType->value => SampleEvent::class],
        ]);
    }

    #[Test]
    public function rejects_a_mistyped_reserved_header_at_the_boundary(): void
    {
        // a reserved framework header with the wrong wire type, here __aggregate_version as a string when it
        // must be an int, is rejected here as a SerializationException, not read back later where it would be
        // silently null, or thrown deep when the store reads it for OCC.
        try {
            (new DefaultMessageSerializer)->deserialize([
                'header' => [
                    Header::MessageType->value => SampleEvent::class,
                    Header::AggregateVersion->value => '5',
                ],
                'content' => ['orderId' => 'order-1', 'amount' => 50],
            ]);
            $this->fail('a mistyped reserved header must be rejected');
        } catch (SerializationException $e) {
            // wrapped with the boundary check's cause chained, not flattened to a bare message
            $this->assertInstanceOf(InvalidMessageException::class, $e->getPrevious());
        }
    }

    // -------------------------------------------------------------------------
    // codec exceptions stay inside the Serializer contract
    // -------------------------------------------------------------------------

    #[Test]
    #[Group('adversarial')]
    public function deserialize_wraps_a_raw_from_payload_exception_as_a_serialization_failure(): void
    {
        // a poison payload must surface as the codec's contracted failure, so the store's read port
        // and the transport's decoding-failure path recognize it, never a raw InvalidArgumentException
        try {
            (new DefaultMessageSerializer)->deserialize([
                'header' => [Header::MessageType->value => ThrowingPayload::class],
                'content' => [], // no `amount`, so fromPayload throws InvalidArgumentException
            ]);
            $this->fail('a raw fromPayload exception must be wrapped');
        } catch (SerializationExceptionContract $e) {
            $this->assertStringContainsString(ThrowingPayload::class, $e->getMessage());
            $this->assertInstanceOf(InvalidArgumentException::class, $e->getPrevious());
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function deserialize_passes_through_an_already_contracted_from_payload_failure(): void
    {
        // the deserialize twin of serialize_does_not_double_wrap: a fromPayload that already throws the
        // codec's contract is re-thrown UNTOUCHED, never re-wrapped under a payloadDeserializationFailed
        // message with a redundant cause.
        try {
            (new DefaultMessageSerializer)->deserialize([
                'header' => [Header::MessageType->value => ContractThrowingPayload::class],
                'content' => [],
            ]);
            $this->fail('expected the contracted exception');
        } catch (SerializationException $e) {
            $this->assertStringContainsString('[amount] field is missing', $e->getMessage());
            $this->assertNull($e->getPrevious()); // passed through, not wrapped
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function serialize_wraps_a_raw_to_payload_exception_as_a_serialization_failure(): void
    {
        $message = new Message(new ThrowingPayload(-1)) // negative, so toPayload throws
            ->withHeader(Header::MessageType, ThrowingPayload::class);

        try {
            (new DefaultMessageSerializer)->serialize($message);
            $this->fail('a raw toPayload exception must be wrapped');
        } catch (SerializationExceptionContract $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function serialize_does_not_double_wrap_an_already_contracted_failure(): void
    {
        // a toPayload that already throws the codec contract is passed through untouched, never
        // re-wrapped under a payloadSerializationFailed message with a redundant cause
        $throwingContract = new class() implements \Storm\Contracts\Message\DomainEvent
        {
            public function aggregateId(): string
            {
                return 'x';
            }

            public function toPayload(): array
            {
                throw SerializationException::missingField('amount');
            }

            public static function fromPayload(array $payload): static
            {
                return new self;
            }
        };

        try {
            (new DefaultMessageSerializer)->serialize(new Message($throwingContract));
            $this->fail('expected the contracted exception');
        } catch (SerializationException $e) {
            $this->assertStringContainsString('[amount] field is missing', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    // -------------------------------------------------------------------------
    // serialize / deserialize symmetry
    // -------------------------------------------------------------------------

    #[Test]
    public function serialize_injects_the_message_type_so_an_unenriched_message_round_trips(): void
    {
        // without the injected MessageType, serialize(new Message($event)) succeeds but deserialize of
        // its output fails for want of a type; serialize injects the type so the message round-trips.
        $serializer = new DefaultMessageSerializer;

        $data = $serializer->serialize(new Message(new SampleEvent('order-1', 50)));

        $this->assertSame(SampleEvent::class, $data['header'][Header::MessageType->value]);

        $restored = $serializer->deserialize($data);
        $this->assertInstanceOf(SampleEvent::class, $restored->message());
    }

    #[Test]
    #[Group('adversarial')]
    public function serialize_rejects_a_message_type_that_mismatches_the_wrapped_class(): void
    {
        // a MessageType that names another class would let a read rebuild the wrong type from a
        // right-looking row; refused at write, not silently overwritten
        $message = new Message(new SampleEvent('order-1', 50))
            ->withHeader(Header::MessageType, ThrowingPayload::class); // lies about the class

        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageMatches('/must name the wrapped class/');

        (new DefaultMessageSerializer)->serialize($message);
    }

    // -------------------------------------------------------------------------
    // round trip
    // -------------------------------------------------------------------------

    #[Test]
    public function round_trip_preserves_payload_and_headers(): void
    {
        $serializer = new DefaultMessageSerializer;

        $original = new Message(new SampleEvent('order-9', 999))
            ->withHeader(Header::MessageType, SampleEvent::class)
            ->withHeader(Header::CorrelationId, 'corr-1');

        $restored = $serializer->deserialize($serializer->serialize($original));

        $this->assertEquals($original->message(), $restored->message());
        $this->assertSame($original->headers(), $restored->headers());
    }

    #[Test]
    #[Group('adversarial')]
    public function serialize_refuses_a_mistyped_framework_header(): void
    {
        // the symmetry gate: what deserialize() would refuse, serialize() refuses too; no more
        // writing an envelope the same codec cannot read back. Built via fromStored, the only door
        // a mistyped bag can still enter by.
        $serializer = new DefaultMessageSerializer;
        $message = Message::fromStored(new SampleEvent('order-1', 50), [Header::CorrelationId->value => 42]);

        $this->expectException(SerializationException::class);

        $serializer->serialize($message);
    }

    #[Test]
    public function an_unknown_reserved_key_round_trips_through_both_gates(): void
    {
        // symmetric tolerance: deserialize accepts an unknown `__` key, such as one on a durable row from
        // another framework version, so serialize must accept re-encoding it; republish would brick otherwise
        $serializer = new DefaultMessageSerializer;
        $message = Message::fromStored(new SampleEvent('order-1', 50), ['__future_framework_key' => 'v']);

        $data = $serializer->serialize($message);
        $decoded = $serializer->deserialize($data);

        $this->assertSame('v', $decoded->header('__future_framework_key'));
    }
}
