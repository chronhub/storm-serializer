# Storm Serializer

The explicit **codec** between a `Message` and its storable / transportable form.

Storm serializes messages **explicitly** (the EventSauce pattern): each domain message
flattens its own value objects into a plain array and rebuilds itself from one. No
reflection, no object-normalization magic — full control over the persisted shape.

## Install

```bash
composer require chronhub/storm-serializer
```

## The codec

A `Message` is encoded to a plain `{header, content}` array:

```php
use Storm\Serializer\MessageSerializer;

final class Foo
{
    public function __construct(private MessageSerializer $serializer) {}

    public function bar(Storm\Message\Message $message): void
    {
        $data = $this->serializer->serialize($message);
        // ['header' => [...], 'content' => [...]]

        $message = $this->serializer->deserialize($data);
    }
}
```

- `header` — the message's header bag.
- `content` — the wrapped message's own `toPayload()`.

The serializer returns **arrays**, not JSON. Each durable adapter json-encodes them onto its
own surface — the event store maps them onto its native columns plus `header`/`content` jsonb,
the saga outbox onto its row, the neutral transport onto the wire body — and translates an
encoding failure into its **own** port's contracted failure: `SerializationException` for the
event store and the wire, `SagaStorageFailure` for the saga outbox. The array→JSON step is the
adapter's boundary, not the codec's; `serialize()` guarantees a storable array, not a byte string.

`SerializedMessage` is the value object those durable surfaces share: the
`{type, version, header, content}` shape of a stored or wired message, owning the
header-to-envelope mapping in one place (the type alias stripped on write and re-injected on
read, the stream name injected on every durable read, the version kept on both the column and
the header). Surfaces that cannot carry an alias or a version make the gap explicit through
`requireType()` / `requireVersion()` instead of guessing.

## `SerializablePayload`

Domain messages serialize themselves. `DomainEvent extends SerializablePayload`, so every
event implements:

```php
use Storm\Contracts\Message\DomainEvent;

final class OrderPlaced implements DomainEvent
{
    public function __construct(
        public string $orderId,
        public int $amount,
    ) {}

    public function toPayload(): array
    {
        return ['orderId' => $this->orderId, 'amount' => $this->amount];
    }

    public static function fromPayload(array $payload): static
    {
        // Validate, never coerce. A cast (`(string) $payload['orderId']`) turns a missing or
        // wrong-typed field into invented data — '' or 0 — and the corrupt message rides on. Assert
        // the shape and fail with the field name; the serializer wraps the throw into its own
        // SerializationException, so the store's read port and the transport's decoding-failure path
        // both recognise it.
        if (! isset($payload['orderId'], $payload['amount']) || ! is_string($payload['orderId']) || ! is_int($payload['amount'])) {
            throw new \InvalidArgumentException('OrderPlaced payload: orderId (string) and amount (int) are required.');
        }

        return new self($payload['orderId'], $payload['amount']);
    }
}
```

> A wire-type change — renaming a field, changing its type — is a schema change. Convert it with an
> upcaster keyed to the event version, not a cast inside `fromPayload()`.

## Type resolution

Decoding reads the **FQCN** from the `Header::MessageType` header and calls
`$class::fromPayload()`. (The event store's stable short-name `type` column — e.g.
`order.placed` — is a separate, later concern handled by the Chronicler.)

`deserialize()` gates the type to a loadable `SerializablePayload`, but that is **not** an
allowlist — it accepts any loadable payload class. On a boundary fed by an untrusted producer,
front the codec with an allowlist: `NeutralMessageSerializer` takes the wire-type aliases its
channel accepts and rejects anything else before a class is resolved or `fromPayload()` runs.
Never hand raw untrusted input straight to `deserialize()`.

## Personal data — the ciphering codec

`CipheringMessageSerializer` is the codec half of crypto-shredding. It decorates the serializer so
a `#[Personal]`-marked class (Message package) has its declared payload keys encrypted at rest
with the SUBJECT's key, rendered back at read, or replaced by their declared fallback once the
subject is forgotten. An unmarked class passes through untouched, and an app with no marked class
never registers the decorator at all.

The write path is fail-fast (a marked class missing its subject or a declared key refuses with
the remedy named; new personal data about a forgotten subject surfaces `SubjectForgotten` — a
compliance bug, not a condition to swallow). The read path is TOTAL: an envelope always renders
cleartext or fallback, never raw ciphertext and never an exception, so a fold stays replayable
whatever a row's key fate. One honest limit, documented rather than hidden: a row written BEFORE
its class was marked was never encrypted — destroying the key erases it from every read through
the codec, not from the store's bytes.

Upcasters run before deserialization, so they see personal fields as opaque envelopes: they may
move or rename one, never transform its cleartext.

**Reading personal data goes through deserialization, never the stored payload.** A SQL expression
such as `content->>'first_name'` renders the envelope `v1:<nonce>:<ciphertext>` and never an
error, so a reader that bypasses the codec silently scores ciphertext — a matcher finds no
candidate and takes its empty branch with a clean motive. The bundle ships a PHPStan rule that
turns this mistake into a diff-time refusal; register it with the same directories
`storm.event_paths` names:

```neon
services:
    -
        class: Storm\Tools\PhpStan\RawPersonalKeyReadRule
        arguments:
            eventPaths:
                - %currentWorkingDirectory%/src
        tags:
            - phpstan.rules.rule
```

The subject key is not flagged: it stays in clear by design, since it locates the cipher key.

## Wiring

`config/services.php` registers `DefaultMessageSerializer`, which carries
`#[AsAlias(MessageSerializer::class)]` — inject the `MessageSerializer` contract anywhere. The
ciphering decorator is wired by the bundle only when the compiled `#[Personal]` map is non-empty;
the key store implementation ships with the Ledger package.

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*
