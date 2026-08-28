<?php

declare(strict_types=1);

namespace Storm\Serializer;

use JsonException;
use Storm\Message\Header;
use Storm\Serializer\Exception\SerializationException;

/**
 * The serialized form of a message: the `{type, header, content}` triple that crosses every storage
 * and wire boundary, whether an event-store row or the neutral transport body.
 *
 * It is the single typed gateway for that shape. The three keys live here once, so no
 * `$row['content']` literals scatter across Chronicler, Story or Saga, and the validating factories
 * check conformity at the boundary, so downstream reads typed fields and trusts them instead of
 * probing a raw array.
 *
 * `type` is the EventType alias, portable; `header` and `content` are the decoded arrays. The
 * event-store metadata that wraps the triple, namely category, stream, version, event_version and
 * sequence_no, is deliberately NOT part of it; it is read alongside, where the row is read. The
 * neutral wire is the one boundary with no row beside it, so THERE the payload schema version rides
 * inside the shape, a fourth `version` field: fromWire() requires it, the row factories leave it
 * null, and requireVersion() makes the "wire shape has a version" invariant explicit, like
 * requireType() for the alias.
 *
 * `type` is nullable because the same gateway also serves the pair form `{header, content}`: the
 * serializer's own shape and the saga outbox, where the class lives in the header as `MessageType` rather
 * than a portable alias. The pair is built via fromPairRow() with a null type; a caller that needs the
 * alias asks for it via requireType().
 *
 * The header-to-envelope mapping lives here in ONE place, each rule also pinned at its own site:
 *
 * - The `type` column or field strips `__message_type` on write; the read re-injects the FQCN resolved
 *   from the alias. This class owns that pair through externalizeType() and internalizeType().
 *
 * - The `stream` column injects `__stream_name` on every durable read, the outbox relay aliasing
 *   `partition_key AS stream`; it is never stored inside the header jsonb.
 *
 * - The `version` column also keeps `__aggregate_version` in the header jsonb, so the wire reads the
 *   header copy; both derive from one Message at write.
 */
final readonly class SerializedMessage
{
    public const string TYPE = 'type';

    public const string VERSION = 'version';

    public const string HEADER = 'header';

    public const string CONTENT = 'content';

    /**
     * Private: every triple is built through a validating factory, fromTriple, fromRow, fromWire or
     * fromPairRow, so the "type is a non-empty string, unless this is a pair" invariant holds by
     * construction and no caller can mint an unchecked shape with `new`.
     *
     * @param  array<string, scalar|array<mixed>|null>  $header
     * @param  array<string, mixed>  $content
     * @param  int|null  $version  the payload schema version inside the shape; carried on the wire
     *                             paths only, null on the row paths, where `event_version` is a
     *                             column beside the triple
     */
    private function __construct(
        public ?string $type,
        public array $header,
        public array $content,
        public ?int $version = null,
    ) {}

    /**
     * Build an encoded wire shape from an already-resolved type, the type's current schema version,
     * and decoded header/content, for the wire encoder. Shares the boundary's type validation: an
     * empty type is refused here too, not only in the parsing factories.
     *
     * @param  array<string, scalar|array<mixed>|null>  $header
     * @param  array<string, mixed>  $content
     *
     * @throws SerializationException when the type is an empty string, or the version is not positive
     */
    public static function fromTriple(string $type, int $version, array $header, array $content): self
    {
        if ($type === '') {
            throw SerializationException::notAString(self::TYPE);
        }

        // the floor the validating decoder enforces: a non-positive version would mint a payload
        // fromWire() refuses to consume
        if ($version < 1) {
            throw SerializationException::malformedField(self::VERSION);
        }

        return new self($type, $header, $content, $version);
    }

    /**
     * The EventType alias, asserted present and non-empty, for a triple consumer that resolves the
     * class from it.
     *
     * @throws SerializationException when this is a pair with no type, a programming error at that call site
     */
    public function requireType(): string
    {
        if ($this->type === null || $this->type === '') {
            throw SerializationException::missingField(self::TYPE);
        }

        return $this->type;
    }

    /**
     * The wire schema version, asserted present, for the neutral decoder that upcasts from it.
     *
     * @throws SerializationException when the shape carries no version, a row-path triple whose
     *                                event_version lives beside it, a programming error at that call site
     */
    public function requireVersion(): int
    {
        return $this->version ?? throw SerializationException::missingField(self::VERSION);
    }

    /**
     * Parse a stored row into a validated triple. The DB columns are `type` as text plus `header` and
     * `content` as JSON text.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws SerializationException when a field is missing, or `header` / `content` is not a JSON array
     */
    public static function fromRow(array $row): self
    {
        return new self(
            self::stringField($row, self::TYPE),
            self::jsonArrayField($row, self::HEADER),
            self::jsonArrayField($row, self::CONTENT),
        );
    }

    /**
     * Parse a neutral wire body `{"type": …, "version": …, "header": {…}, "content": {…}}` into a
     * validated shape. The version is REQUIRED: an absent one would have to be presumed current,
     * a silent lie the moment the type's schema moves past the producer's.
     *
     * @throws SerializationException when the body is not a JSON object carrying the four fields
     */
    public static function fromWire(string $body): self
    {
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SerializationException::malformedField('body');
        }

        if (! is_array($data)) {
            throw SerializationException::malformedField('body');
        }

        return new self(
            self::stringField($data, self::TYPE),
            self::arrayField($data, self::HEADER),
            self::arrayField($data, self::CONTENT),
            self::positiveIntField($data, self::VERSION),
        );
    }

    /**
     * Parse a pair row for the serializer's own shape and the saga outbox. The DB columns are `header`
     * and `content` as JSON text, with no portable `type` because the class lives in the header as
     * `MessageType`.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws SerializationException when `header` / `content` is missing or not a JSON array
     */
    public static function fromPairRow(array $row): self
    {
        return new self(
            null,
            self::jsonArrayField($row, self::HEADER),
            self::jsonArrayField($row, self::CONTENT),
        );
    }

    /**
     * Strips the in-process hydration FQCN, Header::MessageType, from a serialized header. The
     * durable/portable type travels OUTSIDE the body, in the codec's `type` column or field as the
     * EventTypeMapper alias. Shared by the store-row codec EventRowCodec and the neutral wire codec
     * NeutralMessageSerializer; its inverse is internalizeType(). Alias resolution stays at the
     * codecs, which own their mapper.
     *
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    public static function externalizeType(array $header): array
    {
        unset($header[Header::MessageType->value]);

        return $header;
    }

    /**
     * Re-inject the hydration FQCN, resolved by the codec from the external `type` alias, into a header
     * read back from a durable surface; the inverse of externalizeType().
     *
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    public static function internalizeType(array $header, string $class): array
    {
        $header[Header::MessageType->value] = $class;

        return $header;
    }

    /**
     * The wire/array form, with `header` and `content` as nested arrays for the caller to JSON-encode.
     *
     * @return array{type: string, version: int, header: array<string, scalar|array<mixed>|null>, content: array<string, mixed>}
     *
     * @throws SerializationException when this is a pair with no type to externalize, or a row-path
     *                                triple with no version to put on the wire
     */
    public function toArray(): array
    {
        return [
            self::TYPE => $this->requireType(),
            self::VERSION => $this->requireVersion(),
            self::HEADER => $this->header,
            self::CONTENT => $this->content,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws SerializationException
     */
    private static function stringField(array $data, string $key): string
    {
        if (! array_key_exists($key, $data)) {
            throw SerializationException::missingField($key);
        }

        $value = $data[$key];

        // validate, don't coerce: a non-string / empty `type` must fail at the boundary with an accurate
        // diagnostic, not be cast, where `["x"]` becomes `"Array"` plus a PHP warning, and fail later as "unknown type".
        if (! is_string($value) || $value === '') {
            throw SerializationException::notAString($key);
        }

        return $value;
    }

    /**
     * A JSON-text column decoded into an array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws SerializationException
     */
    private static function jsonArrayField(array $data, string $key): array
    {
        if (! array_key_exists($key, $data)) {
            throw SerializationException::missingField($key);
        }

        $raw = $data[$key];

        // validate, never coerce, the sibling rule: an already-decoded array means the connection
        // maps json columns itself, a wiring this codec does not bless, and a non-stringable object
        // cast would escape as a raw Error outside the closed exception contract
        if (! is_string($raw)) {
            throw SerializationException::malformedField($key);
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SerializationException::malformedField($key);
        }

        if (! is_array($decoded)) {
            throw SerializationException::malformedField($key);
        }

        return $decoded;
    }

    /**
     * A field already decoded as an array within a parsed wire body.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws SerializationException
     */
    private static function arrayField(array $data, string $key): array
    {
        if (! array_key_exists($key, $data)) {
            throw SerializationException::missingField($key);
        }

        if (! is_array($data[$key])) {
            throw SerializationException::malformedField($key);
        }

        return $data[$key];
    }

    /**
     * A positive-integer field within a parsed wire body. Validated, never coerced, like the type:
     * a `"1"` or a zero must fail at the boundary with an accurate diagnostic, not become a version
     * the upcast then trusts.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SerializationException
     */
    private static function positiveIntField(array $data, string $key): int
    {
        if (! array_key_exists($key, $data)) {
            throw SerializationException::missingField($key);
        }

        $value = $data[$key];

        if (! is_int($value) || $value < 1) {
            throw SerializationException::malformedField($key);
        }

        return $value;
    }
}
