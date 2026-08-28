<?php

declare(strict_types=1);

namespace Storm\Serializer\Tests;

use JsonException;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Storm\Contracts\Serializer\CipherKeyStore;
use Storm\Contracts\Serializer\SubjectForgotten;
use Storm\Message\Attribute\Personal;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\CipheringMessageSerializer;
use Storm\Serializer\DefaultMessageSerializer;
use Storm\Serializer\Exception\SerializationException;
use Storm\Serializer\MessageSerializer;
use Storm\Serializer\Tests\Fixture\DriftingPersonalEvent;
use Storm\Serializer\Tests\Fixture\FakeCipherKeyStore;
use Storm\Serializer\Tests\Fixture\OptionalPersonalEvent;
use Storm\Serializer\Tests\Fixture\PersonalSampleEvent;
use Storm\Serializer\Tests\Fixture\SampleEvent;
use Storm\Serializer\Tests\Fixture\SparsePersonalEvent;

/**
 * The adversarial suite of the ciphering codec, holding it to the properties crypto-shredding
 * rests on:
 *
 * - The declared keys encrypt and round-trip
 * - A destroyed key renders every declared fallback
 * - The undeclared stays intact
 * - NO raw ciphertext ever escapes
 * - The read path never throws for a key's fate
 */
final class CipheringMessageSerializerTest extends TestCase
{
    private FakeCipherKeyStore $keys;

    private CipheringMessageSerializer $serializer;

    #[Override]
    protected function setUp(): void
    {
        $this->keys = new FakeCipherKeyStore;
        $this->serializer = new CipheringMessageSerializer(
            new DefaultMessageSerializer,
            $this->keys,
            $this->mapFor(PersonalSampleEvent::class, DriftingPersonalEvent::class),
        );
    }

    // -------------------------------------------------------------------------
    // write path
    // -------------------------------------------------------------------------

    #[Test]
    public function serialize_encrypts_the_declared_keys_and_leaves_subject_and_business_data_clear(): void
    {
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', 'ada@example.com', 50)));

        $this->assertSame('cus-1', $data['content']['customer_id']);
        $this->assertSame(50, $data['content']['amount']);
        $this->assertStringStartsWith('v2:', (string) $data['content']['full_name']);
        $this->assertStringStartsWith('v2:', (string) $data['content']['email']);
        $this->assertStringNotContainsString('Ada Lovelace', json_encode($data, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('ada@example.com', json_encode($data, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function an_unmarked_class_passes_through_byte_identical(): void
    {
        $message = $this->message(new SampleEvent('order-1', 50), SampleEvent::class);

        $this->assertSame(new DefaultMessageSerializer()->serialize($message), $this->serializer->serialize($message));
    }

    #[Test]
    public function serialize_refuses_a_marked_payload_whose_subject_is_blank(): void
    {
        // without the subject the fields would be stored in clear or encrypted under nobody
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageMatches('/customer_id/');

        $this->serializer->serialize($this->message(new PersonalSampleEvent('', 'Ada Lovelace', null, 50)));
    }

    #[Test]
    public function serialize_refuses_a_marked_payload_whose_subject_is_whitespace(): void
    {
        // the degenerate sibling of the blank subject: whitespace is not an identity, and a key
        // issued under "   " would silently shard the subject away from its real id
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageMatches('/customer_id/');

        $this->serializer->serialize($this->message(new PersonalSampleEvent('   ', 'Ada Lovelace', null, 50)));
    }

    #[Test]
    public function serialize_refuses_a_declared_key_the_payload_no_longer_emits(): void
    {
        // declaration drift: a silent skip would store whatever replaced the key in clear
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageMatches('/email/');

        $this->serializer->serialize($this->message(new DriftingPersonalEvent('cus-1', 'Ada Lovelace'), DriftingPersonalEvent::class));
    }

    #[Test]
    public function serialize_refuses_new_personal_data_about_a_forgotten_subject(): void
    {
        $this->keys->destroy('cus-1');

        // issuing a key again would silently resurrect the forgotten identity
        $this->expectException(SubjectForgotten::class);

        $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', null, 50)));
    }

    #[Test]
    public function serialize_refuses_a_personal_value_json_cannot_encode(): void
    {
        // invalid UTF-8 in a personal value: the write path refuses loud, mirroring the codec's own
        // contract; never a raw JsonException, never the value silently stored in clear
        try {
            $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', "\xB1\x31", null, 50)));
            $this->fail('a JSON-unencodable personal value must refuse at serialize');
        } catch (SerializationException $e) {
            $this->assertStringContainsString(PersonalSampleEvent::class, $e->getMessage());
            $this->assertInstanceOf(JsonException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function serialize_surfaces_a_key_store_handing_back_unusable_material(): void
    {
        // the port is public: a broken/foreign implementation returning wrong-length material must
        // surface on the WRITE path; the alternative is storing the value in clear
        $serializer = new CipheringMessageSerializer(
            new DefaultMessageSerializer,
            $this->brokenKeyStore(),
            $this->mapFor(PersonalSampleEvent::class),
        );

        $this->expectException(SerializationException::class);

        $serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', null, 50)));
    }

    #[Test]
    public function serialize_refuses_an_inner_codec_that_hands_back_no_type_header(): void
    {
        // the decorated seam is an INTERFACE, so "the header names the wrapped class" is a
        // composition fact the type cannot express: a foreign codec answering without it would
        // otherwise make the map lookup miss, and the message would be written whole, in clear,
        // reported as a successful serialization
        $headerless = new class() implements MessageSerializer
        {
            public function serialize(Message $message): array
            {
                return ['header' => [], 'content' => ['customer_id' => 'cus-1', 'full_name' => 'Ada Lovelace']];
            }

            public function deserialize(array $data): Message
            {
                throw new RuntimeException('the read path is not under test here');
            }
        };

        $serializer = new CipheringMessageSerializer($headerless, $this->keys, $this->mapFor(PersonalSampleEvent::class));

        $this->expectException(SerializationException::class);
        $this->expectExceptionMessageMatches('/\['.Header::MessageType->value.'\] field is missing/');

        $serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', null, 50)));
    }

    // -------------------------------------------------------------------------
    // read path with a living key
    // -------------------------------------------------------------------------

    #[Test]
    public function round_trips_the_declared_keys_through_encrypt_and_decrypt(): void
    {
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', 'ada@example.com', 50)));

        $event = $this->serializer->deserialize($data)->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('Ada Lovelace', $event->fullName);
        $this->assertSame('ada@example.com', $event->email);
        $this->assertSame(50, $event->amount);
    }

    #[Test]
    public function a_null_personal_value_encrypts_and_round_trips_as_null(): void
    {
        // an optional personal field is emitted as an explicit null; it must encrypt like any value
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', null, 50)));

        $this->assertStringStartsWith('v2:', (string) $data['content']['email']);

        $event = $this->serializer->deserialize($data)->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertNull($event->email);
    }

    #[Test]
    public function cleartext_from_before_the_marking_stays_readable_while_the_subject_is_not_forgotten(): void
    {
        // a row written before the class carried #[Personal]: gradual adoption must not redact history
        $event = $this->serializer->deserialize($this->storedRow('cus-1', 'Ada Lovelace', 'ada@example.com'))->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('Ada Lovelace', $event->fullName);
        $this->assertSame('ada@example.com', $event->email);
    }

    #[Test]
    public function a_legitimate_value_that_merely_starts_with_the_prefix_is_not_mistaken_for_an_envelope(): void
    {
        $event = $this->serializer->deserialize($this->storedRow('cus-1', 'v1:notbase64:!', 'ada@example.com'))->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('v1:notbase64:!', $event->fullName);
    }

    #[Test]
    public function a_structurally_malformed_envelope_shape_stays_a_cleartext_value(): void
    {
        // the strictness clause of isEnvelope(), held shape by shape: each value below fails
        // exactly ONE structural test, wrong prefix, undecodable nonce, empty ciphertext or
        // undecodable ciphertext, and must pass through as the cleartext it is. The mutant of any
        // relaxed check turns the value into an "envelope" whose decrypt fails, and the fallback
        // replaces what should have survived
        $nonce = base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES));
        $shapes = [
            'xx:'.$nonce.':'.base64_encode('data'),
            'v1:short:'.base64_encode('data'),
            'v1:'.$nonce.':',
            'v1:'.$nonce.':!!!',
        ];

        foreach ($shapes as $shape) {
            $event = $this->serializer->deserialize($this->storedRow('cus-1', $shape, 'ada@example.com'))->message();

            $this->assertInstanceOf(PersonalSampleEvent::class, $event);
            $this->assertSame($shape, $event->fullName);
        }
    }

    #[Test]
    public function an_integer_subject_is_normalized_to_its_string(): void
    {
        // a domain whose opaque id is numeric: the subject key may deserialize as an int, and the
        // lookup must land on the SAME key the write path issued for its string form
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('7', 'Ada Lovelace', null, 50)));
        $data['content']['customer_id'] = 7;

        $event = $this->serializer->deserialize($data)->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('Ada Lovelace', $event->fullName);
    }

    #[Test]
    public function a_marked_type_with_a_non_array_content_passes_through_to_the_inner_gates(): void
    {
        // a shape-violating caller: the decorator must not explode on the string itself; it passes
        // through, and the inner codec owns the error contract
        $this->expectException(SerializationException::class);

        $this->serializer->deserialize([ // @phpstan-ignore argument.type (hostile on purpose: the passthrough under test)
            'header' => [Header::MessageType->value => PersonalSampleEvent::class, Header::MessageId->value => 'evt-1'],
            'content' => 'garbage',
        ]);
    }

    // -------------------------------------------------------------------------
    // read path with a destroyed or unusable key: fallbacks, never ciphertext, never a throw
    // -------------------------------------------------------------------------

    #[Test]
    public function a_destroyed_key_renders_every_declared_fallback_and_the_undeclared_stays_intact(): void
    {
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', 'ada@example.com', 50)));
        $this->keys->destroy('cus-1');

        $event = $this->serializer->deserialize($data)->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('⌫', $event->fullName);
        $this->assertNull($event->email);
        $this->assertSame(50, $event->amount);
        $this->assertSame('cus-1', $event->customerId);
    }

    #[Test]
    public function no_raw_ciphertext_ever_reaches_the_rebuilt_event(): void
    {
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', 'ada@example.com', 50)));
        $this->keys->destroy('cus-1');

        $event = $this->serializer->deserialize($data)->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertDoesNotMatchRegularExpression('/v\d+:/', $event->fullName);
        $this->assertDoesNotMatchRegularExpression('/v\d+:/', (string) $event->email);
    }

    #[Test]
    public function a_tampered_envelope_renders_the_fallback_not_an_exception(): void
    {
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', 'ada@example.com', 50)));

        // flip the last ciphertext character: authentication must fail closed, into the fallback
        $envelope = (string) $data['content']['full_name'];
        $data['content']['full_name'] = substr($envelope, 0, -1).($envelope[-1] === 'A' ? 'B' : 'A');

        $event = $this->serializer->deserialize($data)->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('⌫', $event->fullName);
        $this->assertSame('ada@example.com', $event->email);
    }

    #[Test]
    public function an_envelope_cut_and_pasted_across_subjects_fails_to_the_fallback(): void
    {
        // the subject id is the AEAD's additional data: another subject's envelope must not decrypt
        $ada = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', null, 50)));
        $bob = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-2', 'Bob Martin', null, 60)));

        $bob['content']['full_name'] = $ada['content']['full_name'];

        $event = $this->serializer->deserialize($bob)->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('⌫', $event->fullName);
    }

    #[Test]
    public function an_envelope_cut_and_pasted_across_fields_of_one_subject_fails_to_the_fallback(): void
    {
        // v2 binds subject, class AND field: within one subject an envelope is no longer freely
        // portable, so a swapped email cannot present itself as an authentic full name
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', 'ada@example.com', 50)));

        [$data['content']['full_name'], $data['content']['email']] = [$data['content']['email'], $data['content']['full_name']];

        $event = $this->serializer->deserialize($data)->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('⌫', $event->fullName);
        $this->assertNull($event->email);
    }

    #[Test]
    public function a_legacy_v1_envelope_sealed_under_the_subject_alone_still_opens(): void
    {
        // the corpus written before the slot binding: v1 rows must stay readable forever, the
        // version prefix existing precisely so the binding change is an upcast, not a migration
        $material = $this->keys->issue('cus-1');
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $legacy = 'v1:'.base64_encode($nonce).':'.base64_encode(
            sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(json_encode('Ada Lovelace', JSON_THROW_ON_ERROR), 'cus-1', $nonce, $material),
        );

        $event = $this->serializer->deserialize($this->storedRow('cus-1', $legacy, 'ada@example.com'))->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('Ada Lovelace', $event->fullName);
    }

    #[Test]
    public function a_key_store_failing_on_infrastructure_bubbles_never_renders_fallbacks(): void
    {
        // the ONE deliberate exception to read-path totality: rendering fallbacks over a database
        // blink would present redaction as truth, so the store's own failure must escape loud
        $serializer = new CipheringMessageSerializer(
            new DefaultMessageSerializer,
            $this->downKeyStore(),
            $this->mapFor(PersonalSampleEvent::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('key store down');

        $serializer->deserialize($this->storedRow('cus-1', 'Ada Lovelace', 'ada@example.com'));
    }

    #[Test]
    public function a_shape_carrying_none_of_the_declared_keys_never_touches_the_key_store(): void
    {
        // the one safe skip: nothing to decrypt AND nothing the forgotten-subject rule could
        // redact, so the store round trip is spared; proven with a store that would throw on any
        // access
        $serializer = new CipheringMessageSerializer(
            new DefaultMessageSerializer,
            $this->downKeyStore(),
            $this->mapFor(OptionalPersonalEvent::class),
        );

        $event = $serializer->deserialize([
            'header' => [Header::MessageType->value => OptionalPersonalEvent::class, Header::MessageId->value => 'evt-1'],
            'content' => ['customer_id' => 'cus-1'],
        ])->message();

        $this->assertInstanceOf(OptionalPersonalEvent::class, $event);
        $this->assertNull($event->nickname);
    }

    #[Test]
    public function the_v2_additional_data_layout_is_a_wire_contract(): void
    {
        // subject NUL class NUL key, exactly: v2 rows are durable, so the tuple's layout is pinned
        // the black-box way, decrypting a sealed envelope against the documented bytes
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', null, 50)));
        [, $nonce, $ciphertext] = explode(':', (string) $data['content']['full_name']);

        $clear = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            (string) base64_decode($ciphertext, true),
            'cus-1'."\0".PersonalSampleEvent::class."\0".'full_name',
            (string) base64_decode($nonce, true),
            (string) $this->keys->keyFor('cus-1'),
        );

        $this->assertSame('"Ada Lovelace"', $clear);
    }

    #[Test]
    public function a_drifted_declaration_never_mints_a_key_for_its_subject(): void
    {
        // the declaration gate runs before the store: a failed write reports DRIFT, and it must
        // not leave a durable key row behind, nor a "forgotten subject" misdiagnosis on retry
        try {
            $this->serializer->serialize($this->message(new DriftingPersonalEvent('cus-1', 'Ada Lovelace'), DriftingPersonalEvent::class));
            $this->fail('expected the drift refusal');
        } catch (SerializationException) {
            $this->assertNull($this->keys->keyFor('cus-1'), 'no key row was minted by the refused write');
        }
    }

    #[Test]
    public function an_authenticated_envelope_whose_cleartext_is_not_json_falls_back(): void
    {
        // forged WITH the real key by a buggy or foreign writer: it authenticates, decrypts, and
        // its cleartext is not JSON. The read stays total: the fallback, never a JsonException.
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', null, 50)));

        $material = $this->keys->issue('cus-1');
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $forged = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt('not-json{', 'cus-1', $nonce, $material);
        $data['content']['full_name'] = 'v1:'.base64_encode($nonce).':'.base64_encode($forged);

        $event = $this->serializer->deserialize($data)->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('⌫', $event->fullName);
    }

    #[Test]
    public function unusable_key_material_on_read_falls_back_never_throws(): void
    {
        // the broken-store twin of the write-path refusal, on the READ side: a fold must stay
        // total, so wrong-length material renders the fallback instead of crashing the replay
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', null, 50)));

        $event = new CipheringMessageSerializer(new DefaultMessageSerializer, $this->brokenKeyStore(), $this->mapFor(PersonalSampleEvent::class))
            ->deserialize($data)
            ->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('⌫', $event->fullName);
    }

    #[Test]
    public function cleartext_renders_fallbacks_once_the_subject_is_forgotten(): void
    {
        // the uniform post-forget read surface: even a pre-marking cleartext row shows no personal
        // value once the tombstone exists; the bytes remain in the store, the codec says fallback
        $this->keys->issue('cus-1');
        $this->keys->destroy('cus-1');

        $event = $this->serializer->deserialize($this->storedRow('cus-1', 'Ada Lovelace', 'ada@example.com'))->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('⌫', $event->fullName);
        $this->assertNull($event->email);
    }

    #[Test]
    public function a_row_without_its_subject_still_replaces_envelopes_with_fallbacks(): void
    {
        // no subject = no key to try: an envelope must fall back rather than pass through or throw
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', null, 50)));
        unset($data['content']['customer_id']);

        $event = $this->serializer->deserialize($data)->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('⌫', $event->fullName);
    }

    #[Test]
    public function an_older_shape_missing_a_declared_key_deserializes_without_error(): void
    {
        $data = $this->serializer->serialize($this->message(new PersonalSampleEvent('cus-1', 'Ada Lovelace', 'ada@example.com', 50)));
        unset($data['content']['email']); // an old-version row; shape migration is the upcasters' job

        $event = $this->serializer->deserialize($data)->message();

        $this->assertInstanceOf(PersonalSampleEvent::class, $event);
        $this->assertSame('Ada Lovelace', $event->fullName);
        $this->assertNull($event->email);
    }

    #[Test]
    public function a_missing_first_declared_key_does_not_stop_the_later_keys_from_rendering(): void
    {
        // the gap sits BEFORE a key that still carries its envelope: skipping the absent key must
        // not abandon the walk, or the later envelope would reach the rebuilt event raw
        $serializer = new CipheringMessageSerializer(new DefaultMessageSerializer, $this->keys, $this->mapFor(SparsePersonalEvent::class));
        $data = $serializer->serialize($this->message(new SparsePersonalEvent('cus-1', 'Ada', 'ada@example.com'), SparsePersonalEvent::class));
        unset($data['content']['nickname']);

        $event = $serializer->deserialize($data)->message();

        $this->assertInstanceOf(SparsePersonalEvent::class, $event);
        $this->assertSame('ada@example.com', $event->email);
    }

    // -------------------------------------------------------------------------
    // harness
    // -------------------------------------------------------------------------

    /**
     * @param  class-string  $type
     */
    private function message(object $event, string $type = PersonalSampleEvent::class): Message
    {
        return new Message($event)
            ->withHeader(Header::MessageType, $type)
            ->withHeader(Header::MessageId, 'evt-1');
    }

    /**
     * A stored `{header, content}` row with CLEARTEXT personal values, as written before the class
     * was marked.
     *
     * @return array{header: array<string, mixed>, content: array<string, mixed>}
     */
    private function storedRow(string $subject, string $fullName, string $email): array
    {
        return [
            'header' => [Header::MessageType->value => PersonalSampleEvent::class, Header::MessageId->value => 'evt-1'],
            'content' => ['customer_id' => $subject, 'full_name' => $fullName, 'email' => $email, 'amount' => 50],
        ];
    }

    /**
     * A key store whose material no cipher can use, the broken or foreign implementation of the
     * public port: 5 bytes where sodium requires 32.
     */
    private function brokenKeyStore(): CipherKeyStore
    {
        return new class() implements CipherKeyStore
        {
            public function keyFor(string $subject): string
            {
                return 'short';
            }

            public function issue(string $subject): string
            {
                return 'short';
            }

            public function destroy(string $subject): bool
            {
                return true;
            }

            public function isDestroyed(string $subject): bool
            {
                return false;
            }
        };
    }

    /**
     * A key store that refuses ANY access: the witness of paths that must never touch the store.
     */
    private function downKeyStore(): CipherKeyStore
    {
        return new class() implements CipherKeyStore
        {
            public function keyFor(string $subject): ?string
            {
                throw new RuntimeException('key store down');
            }

            public function issue(string $subject): string
            {
                throw new RuntimeException('key store down');
            }

            public function destroy(string $subject): bool
            {
                return false;
            }

            public function isDestroyed(string $subject): bool
            {
                return false;
            }
        };
    }

    /**
     * The map exactly as `RegisterPersonalDataPass` compiles it, derived from the fixtures' own
     * attributes so the declaration stays the single source.
     *
     * @param  class-string  ...$classes
     * @return array<class-string, array{subject: string, keys: list<string>, fallbacks: array<string, scalar|null>}>
     */
    private function mapFor(string ...$classes): array
    {
        $map = [];
        foreach ($classes as $class) {
            $attribute = new ReflectionClass($class)->getAttributes(Personal::class)[0]->newInstance();
            $map[$class] = ['subject' => $attribute->subject, 'keys' => $attribute->keys, 'fallbacks' => $attribute->fallback];
        }

        return $map;
    }
}
