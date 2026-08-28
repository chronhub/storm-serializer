<?php

declare(strict_types=1);

namespace Storm\Serializer\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Serializer\Exception\SerializationException;
use Storm\Serializer\SerializedMessage;

/**
 * The typed gateway for the stored triple and the neutral wire shape: the factories validate the shape
 * once at the boundary, throwing on a missing field or non-JSON content, so callers read typed fields
 * instead of probing a raw array. The whole point is that conformity is assertable here.
 */
final class SerializedMessageTest extends TestCase
{
    #[Test]
    public function from_row_decodes_the_triple(): void
    {
        $message = SerializedMessage::fromRow([
            'type' => 'bank.money_deposited',
            'header' => '{"__message_id":"abc"}',
            'content' => '{"amount":100}',
        ]);

        self::assertSame('bank.money_deposited', $message->type);
        self::assertSame(['__message_id' => 'abc'], $message->header);
        self::assertSame(['amount' => 100], $message->content);
    }

    #[Test]
    public function from_row_rejects_a_missing_field(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageIsOrContains('[content] field is missing');

        SerializedMessage::fromRow(['type' => 'x', 'header' => '{}']); // no content
    }

    #[Test]
    public function from_row_rejects_non_json_content(): void
    {
        $this->expectException(SerializationException::class);

        SerializedMessage::fromRow(['type' => 'x', 'header' => '{}', 'content' => 'not-json']);
    }

    #[Test]
    public function from_row_refuses_an_already_decoded_array_instead_of_coercing(): void
    {
        // validate, never coerce: a connection mapping json columns to arrays itself is a wiring
        // this codec does not bless, and the string cast would fail later as "Array", a misleading
        // diagnostic for a wiring mistake
        $this->expectException(SerializationException::class);

        SerializedMessage::fromRow(['type' => 'x', 'header' => ['already' => 'decoded'], 'content' => '{}']);
    }

    #[Test]
    public function from_row_refuses_a_non_stringable_object_inside_the_error_contract(): void
    {
        // a raw Error out of the string cast would escape the module's single-error-contract
        // promise; the refusal stays a SerializationException with the accurate field
        $this->expectException(SerializationException::class);

        SerializedMessage::fromRow(['type' => 'x', 'header' => new stdClass, 'content' => '{}']);
    }

    #[Test]
    public function from_wire_parses_the_neutral_body(): void
    {
        $message = SerializedMessage::fromWire('{"type":"bank.x","version":2,"header":{"k":"v"},"content":{"amount":100}}');

        self::assertSame('bank.x', $message->type);
        self::assertSame(2, $message->version);
        self::assertSame(['k' => 'v'], $message->header);
        self::assertSame(['amount' => 100], $message->content);
    }

    #[Test]
    public function from_wire_rejects_a_missing_version(): void
    {
        // an absent version would have to be presumed current, a silent lie once the schema moves
        $this->expectException(SerializationException::class);

        SerializedMessage::fromWire('{"type":"bank.x","header":{},"content":{}}');
    }

    #[Test]
    public function from_wire_rejects_a_non_positive_version(): void
    {
        // validated, never coerced: a zero or a "1" must fail at the boundary, not reach the upcast
        $this->expectException(SerializationException::class);

        SerializedMessage::fromWire('{"type":"bank.x","version":0,"header":{},"content":{}}');
    }

    #[Test]
    public function from_wire_rejects_a_malformed_body(): void
    {
        $this->expectException(SerializationException::class);

        SerializedMessage::fromWire('not-json');
    }

    #[Test]
    public function from_wire_rejects_a_non_string_type(): void
    {
        // a foreign / corrupt producer sending a non-string type must fail at the boundary, not be coerced
        $this->expectException(SerializationException::class);

        SerializedMessage::fromWire('{"type":["x"],"header":{},"content":{}}');
    }

    #[Test]
    public function from_wire_rejects_an_empty_type(): void
    {
        $this->expectException(SerializationException::class);

        SerializedMessage::fromWire('{"type":"","header":{},"content":{}}');
    }

    #[Test]
    public function to_array_yields_the_wire_shape(): void
    {
        $array = SerializedMessage::fromTriple('bank.x', 1, ['h' => 1], ['c' => 2])->toArray();

        self::assertSame(['type' => 'bank.x', 'version' => 1, 'header' => ['h' => 1], 'content' => ['c' => 2]], $array);
    }

    #[Test]
    public function from_triple_rejects_an_empty_type(): void
    {
        // the public constructor is gone; the encoder's factory shares the boundary's type validation,
        // so an empty type can no longer be minted with `new` and fail later as "unknown type"
        $this->expectException(SerializationException::class);

        SerializedMessage::fromTriple('', 1, [], []);
    }

    #[Test]
    #[Group('adversarial')]
    public function from_triple_rejects_the_non_positive_version_the_wire_factory_rejects(): void
    {
        // the encoder factory holds the floor its validating decoder enforces: a non-positive
        // version would mint a payload fromWire() refuses to consume
        $this->expectException(SerializationException::class);

        SerializedMessage::fromTriple('bank.x', 0, [], []);
    }

    #[Test]
    public function require_version_throws_on_a_row_triple(): void
    {
        // a row-path triple carries no version inside the shape, event_version is a column beside it
        $this->expectException(SerializationException::class);

        SerializedMessage::fromRow(['type' => 'x', 'header' => '{}', 'content' => '{}'])->requireVersion();
    }

    #[Test]
    public function from_pair_row_decodes_header_and_content_with_no_type(): void
    {
        $pair = SerializedMessage::fromPairRow(['header' => '{"__message_type":"App\\\\Cmd"}', 'content' => '{"x":1}']);

        self::assertNull($pair->type);
        self::assertSame(['__message_type' => 'App\\Cmd'], $pair->header);
        self::assertSame(['x' => 1], $pair->content);
    }

    #[Test]
    public function require_type_throws_on_a_pair(): void
    {
        $this->expectException(SerializationException::class);

        SerializedMessage::fromPairRow(['header' => '{}', 'content' => '{}'])->requireType();
    }

    #[Test]
    public function from_wire_rejects_a_scalar_json_body(): void
    {
        // valid JSON, hostile shape: "42" decodes fine but is no object; the untrusted boundary refuses
        $this->expectException(SerializationException::class);

        SerializedMessage::fromWire('"42"');
    }

    #[Test]
    public function from_row_rejects_a_missing_type(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageIsOrContains('[type] field is missing');

        SerializedMessage::fromRow(['header' => '{}', 'content' => '{}']);
    }

    #[Test]
    public function from_row_rejects_a_json_scalar_column(): void
    {
        // the column holds VALID json that is not an array, a corrupt or hand-edited row
        $this->expectException(SerializationException::class);

        SerializedMessage::fromRow(['type' => 'order.placed', 'header' => '"x"', 'content' => '{}']);
    }

    #[Test]
    public function from_wire_rejects_a_missing_header(): void
    {
        // the FIELD is the whole diagnostic: every refusal in this class carries the same class and
        // the same opening words, so a message naming the wrong one, or naming the wrong KIND of
        // wrong, sends a producer looking at a field that is perfectly fine
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageIsOrContains('[header] field is missing');

        SerializedMessage::fromWire('{"type":"order.placed","content":{}}');
    }

    #[Test]
    public function from_wire_rejects_a_non_array_header(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageIsOrContains('[header] field is not a valid JSON array');

        SerializedMessage::fromWire('{"type":"order.placed","header":"x","content":{}}');
    }

    #[Test]
    public function from_wire_rejects_a_missing_content(): void
    {
        // the twin of the pair above, which had the reasoning and not the sibling: both fields go
        // through the same helper, so a key hard-coded there names one of the two for BOTH
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageIsOrContains('[content] field is missing');

        SerializedMessage::fromWire('{"type":"order.placed","header":{}}');
    }

    #[Test]
    public function from_wire_rejects_a_non_array_content(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageIsOrContains('[content] field is not a valid JSON array');

        SerializedMessage::fromWire('{"type":"order.placed","header":{},"content":"x"}');
    }
}
