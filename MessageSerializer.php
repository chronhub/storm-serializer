<?php

declare(strict_types=1);

namespace Storm\Serializer;

use Storm\Contracts\Message\SerializablePayload;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Message\Message;

/**
 * Codec between a Message and its storable / transportable representation.
 *
 * The representation is a plain `{header, content}` array rather than JSON: `header` is the
 * message's header bag, `content` is the wrapped message's own SerializablePayload. The Chronicler
 * maps this array onto the event store's native columns plus the `header`/`content` jsonb, leaving
 * DBAL to encode the JSON; the outbox and transport will reuse it later.
 *
 * This contract lives in the Serializer package rather than Contracts because it references the
 * concrete Message, keeping `chronhub/storm-contracts` free of any dependency on an implementation
 * package.
 *
 * @see \Storm\Contracts\Message\SerializablePayload
 */
interface MessageSerializer
{
    /**
     * Serializes the message into a plain array.
     *
     * The wire is explicit-only: the message supplies its own `content` via
     * SerializablePayload::toPayload(); anything without the pair fails fast with the remedy in the
     * message.
     *
     * @return array{header: array<string, scalar|array<mixed>|null>, content: array<string, mixed>}
     *
     * @throws SerializationExceptionContract when the wrapped message does not implement
     *                                        SerializablePayload, or when the message's own
     *                                        toPayload() fails; a raw payload exception is wrapped,
     *                                        never let through
     */
    public function serialize(Message $message): array;

    /**
     * Deserializes the message from a plain array.
     *
     * @param  array{header: array<string, scalar|array<mixed>|null>, content: array<string, mixed>}  $data
     *
     * @throws SerializationExceptionContract when the header or content is missing, the header is
     *                                        malformed, the message type is absent, not a loadable
     *                                        class or not a SerializablePayload, or when the type's
     *                                        own fromPayload() fails; a raw payload exception is
     *                                        wrapped, never let through
     */
    public function deserialize(array $data): Message;
}
