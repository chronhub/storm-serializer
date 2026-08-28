<?php

declare(strict_types=1);

namespace Storm\Serializer;

use Storm\Contracts\Message\SerializablePayload;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\Exception\SerializationException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

/**
 * The MessageSerializer: explicit, and nothing else.
 *
 * The wire stays a handwritten, refactor-safe contract: no reflection, ever. `content` is the
 * message's own SerializablePayload::toPayload(); every domain event carries it STRUCTURALLY, since
 * DomainEvent extends SerializablePayload, and a command that crosses a durable boundary implements
 * it too through the HasConstructablePayload trait when the payload is the shape. Decoding mirrors:
 * the FQCN from Header::MessageType feeds fromPayload().
 */
#[AsAlias(MessageSerializer::class)]
final readonly class DefaultMessageSerializer implements MessageSerializer
{
    public function serialize(Message $message): array
    {
        $messaging = $message->message();

        if (! $messaging instanceof SerializablePayload) {
            throw SerializationException::notSerializablePayload($messaging::class);
        }

        // A validating gate, so every array serialize() accepts is a valid deserialize() input. The
        // header must name the wrapped class: a declared MessageType that disagrees is refused, not
        // written; an absent one is injected, so the `{header, content}` form is self-contained and
        // round-trips even for a message that skipped enrichment.
        $headers = $message->headers();
        $declared = $headers[Header::MessageType->value] ?? null;

        if ($declared !== null && $declared !== $messaging::class) {
            throw SerializationException::messageTypeMismatch(
                $messaging::class,
                is_string($declared) ? $declared : get_debug_type($declared),
            );
        }

        $headers[Header::MessageType->value] = $messaging::class;

        try {
            // the same well-typed check deserialize() applies, so the symmetry is total: an envelope
            // this gate writes is an envelope the other gate reads back. Unknown `__` keys pass on
            // BOTH sides; a re-encoded stored header may legitimately carry one from another version.
            Header::assertWellTyped($headers);
        } catch (InvalidMessageException $e) {
            throw SerializationException::malformedHeader($e);
        }

        try {
            $content = $messaging->toPayload();
        } catch (Throwable $e) {
            // keep the codec's single error contract: never let a raw payload exception escape serialize()
            throw $e instanceof SerializationExceptionContract
                ? $e
                : SerializationException::payloadSerializationFailed($messaging::class, $e);
        }

        return [
            'header' => $headers,
            'content' => $content,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * Trust precondition: `$data` is already structurally validated upstream by the SerializedMessage
     * factories. This method gates the `type` to a loadable SerializablePayload but does NOT itself
     * enforce an allowlist; `is_a(..., true)` accepts ANY loadable payload class, not only registered
     * ones. Allowlisting the wire type is the untrusted boundary's job: NeutralMessageSerializer rejects
     * a type outside its channel allowlist before reaching here. A caller that feeds raw untrusted input
     * without that front gate accepts arbitrary payload instantiation; the exact surface is wider than
     * instantiation, since `class_exists()` AUTOLOADS, executing any loadable class's file before the
     * interface narrowing runs.
     */
    public function deserialize(array $data): Message
    {
        // @phpstan-ignore nullCoalesce.offset (the @param shape guarantees `header`; the ?? is the runtime belt-and-suspenders for a shape-violating caller, a clean error over a warning + TypeError)
        $header = $data['header'] ?? throw SerializationException::missingField('header');

        // @phpstan-ignore nullCoalesce.offset (same belt-and-suspenders as `header`, a clean error over feeding fromPayload() a missing key)
        $content = $data['content'] ?? throw SerializationException::missingField('content');

        try {
            Header::assertWellTyped($header); // reject a mistyped reserved key here, at the still-untrusted boundary
        } catch (InvalidMessageException $e) {
            throw SerializationException::malformedHeader($e);
        }

        $type = $header[Header::MessageType->value] ?? null;

        if (! is_string($type) || ! class_exists($type)) {
            throw SerializationException::cannotDeserialize(is_string($type) ? $type : get_debug_type($type));
        }

        if (! is_a($type, SerializablePayload::class, true)) {
            throw SerializationException::notSerializablePayload($type);
        }

        try {
            $payload = $type::fromPayload($content);
        } catch (Throwable $e) {
            // a corrupt/incompatible payload becomes the codec's contracted failure, so the store's read
            // port and the transport's decoding-failure path recognize it instead of a raw exception
            throw $e instanceof SerializationExceptionContract
                ? $e
                : SerializationException::payloadDeserializationFailed($type, $e);
        }

        // the named hydration gate: the header form was asserted above, and a row written by another
        // framework version may carry an unknown reserved key the strict constructor would refuse
        return Message::fromStored($payload, $header);
    }
}
