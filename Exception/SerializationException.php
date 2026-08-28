<?php

declare(strict_types=1);

namespace Storm\Serializer\Exception;

use RuntimeException;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Throwable;

/**
 * Thrown when a message cannot be serialized or deserialized.
 */
final class SerializationException extends RuntimeException implements SerializationExceptionContract
{
    /**
     * A reserved framework header carried the wrong wire type. Wraps the Message-layer
     * `InvalidMessageException` so the serialization boundary speaks its own exception, with the
     * precise key/expected/actual preserved in the chain.
     */
    public static function malformedHeader(Throwable $previous): self
    {
        return new self($previous->getMessage(), previous: $previous);
    }

    /**
     * The message's header or content could not be JSON-encoded for storage. This is the write-path
     * twin of the malformed* read failures, so the persistence boundary surfaces serialization, not
     * a raw `JsonException`.
     */
    public static function cannotEncode(Throwable $previous): self
    {
        return new self('Cannot JSON-encode the message header or content for storage.', previous: $previous);
    }

    /**
     * A message was serialized carrying a `Header::MessageType` that names a different class than
     * the one actually wrapped. Refused at `serialize()` rather than written, so a later read cannot
     * silently rebuild the wrong type from a right-looking row.
     */
    public static function messageTypeMismatch(string $wrapped, string $declared): self
    {
        return new self(sprintf(
            'Cannot serialize [%s]: its Header::MessageType declares [%s] — the header must name the wrapped class.',
            $wrapped,
            $declared,
        ));
    }

    public static function cannotDeserialize(string $type): self
    {
        return new self(sprintf(
            'Cannot deserialize message: type [%s] is missing or is not a loadable class.',
            $type,
        ));
    }

    public static function missingField(string $field): self
    {
        return new self(sprintf('Malformed serialized message: the [%s] field is missing.', $field));
    }

    public static function malformedField(string $field): self
    {
        return new self(sprintf('Malformed serialized message: the [%s] field is not a valid JSON array.', $field));
    }

    public static function notAString(string $field): self
    {
        return new self(sprintf('Malformed serialized message: the [%s] field must be a non-empty string.', $field));
    }

    /**
     * The codec's fail-fast: the wire is explicit-only, so a message without its own
     * `toPayload()`/`fromPayload()` pair refuses at the boundary; the actionable remedy rides in the
     * message. It never reflects into an accidental shape a later refactor would quietly change.
     */
    public static function notSerializablePayload(string $class): self
    {
        return new self(sprintf(
            'Cannot serialize [%s]: the wire is explicit-only — implement SerializablePayload (the HasConstructablePayload trait covers the payload-is-the-shape case).',
            $class,
        ));
    }

    /**
     * A `#[Personal]`-marked class was serialized without its subject key, or with a blank one. The
     * subject id locates the cipher key, so without it the declared fields would be stored in clear
     * or encrypted under nobody, both silent conformance holes; the write path refuses instead.
     */
    public static function missingPersonalSubject(string $class, string $subjectKey): self
    {
        return new self(sprintf(
            'Cannot serialize [%s]: its #[Personal] subject key "%s" is missing or blank in the payload — a marked event must carry the opaque id of whose data it is.',
            $class,
            $subjectKey,
        ));
    }

    /**
     * A `#[Personal]`-marked class's payload no longer emits a declared personal key, which is
     * declaration drift. A silent skip would store whatever replaced the key in clear, so the write
     * path refuses; an optional personal field is emitted as an explicit null, which encrypts fine.
     */
    public static function missingPersonalKey(string $class, string $key): self
    {
        return new self(sprintf(
            'Cannot serialize [%s]: its #[Personal] key "%s" is not in the payload — the declaration and toPayload() drifted apart (an optional personal field must be emitted as an explicit null).',
            $class,
            $key,
        ));
    }

    /**
     * A message's own `toPayload()` threw while flattening itself. Wrapping it here keeps the
     * codec's single error contract: the store and transport boundaries see
     * `SerializationExceptionContract`, not whatever raw exception the payload code raised. The
     * cause rides in `previous`; the payload itself is never put in the message, it may hold
     * sensitive data.
     */
    public static function payloadSerializationFailed(string $class, Throwable $previous): self
    {
        return new self(
            sprintf('Cannot serialize [%s]: its toPayload() failed.', $class),
            previous: $previous,
        );
    }

    /**
     * A type's `fromPayload()` threw while rebuilding itself from stored/wire content, for example
     * a missing or wrong-typed field. Wrapping it keeps the single error contract, so a poison wire
     * message reaches the transport's decoding-failure path and a corrupt stored row the storage
     * port, instead of a raw application exception crashing the worker or escaping the port.
     */
    public static function payloadDeserializationFailed(string $class, Throwable $previous): self
    {
        return new self(
            sprintf('Cannot deserialize [%s]: its fromPayload() failed.', $class),
            previous: $previous,
        );
    }
}
