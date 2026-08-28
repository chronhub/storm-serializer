<?php

declare(strict_types=1);

namespace Storm\Serializer;

use JsonException;
use Random\RandomException;
use SodiumException;
use Storm\Contracts\Serializer\CipherKeyStore;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Contracts\Serializer\SubjectForgotten;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\Exception\SerializationException;

/**
 * The ciphering half of crypto-shredding: wraps the codec so a `#[Personal]`-marked class has its
 * declared payload keys encrypted at rest with the SUBJECT's key, and rendered back at read, or
 * replaced by their declared fallback once the subject is forgotten. An unmarked class passes
 * through untouched; a container whose compiled map is empty never registers this decorator at all.
 *
 * The write path is fail-fast: a marked class whose payload misses its subject or a declared key
 * refuses with the remedy in the message, and issuing a key for a tombstoned subject bubbles the
 * store's {@see \Storm\Contracts\Serializer\SubjectForgotten}; new personal data about a forgotten subject is a compliance bug
 * to surface.
 *
 * The read path is TOTAL: an envelope always renders its cleartext or its fallback, never the raw
 * ciphertext and never an exception, so a fold stays replayable whatever a row's key fate. The one
 * deliberate exception to totality is the key store ITSELF failing, its `keyFor()` throwing on
 * infrastructure; that bubbles, because rendering fallbacks over a database blink would present
 * redaction as truth. Per declared key:
 *
 * - An ENVELOPE value decrypts with the subject's key, or renders the fallback when the key is
 *   destroyed or absent, or when the envelope does not authenticate: tampered, cut-and-pasted out
 *   of its slot since subject, class and field are the AEAD's additional data, or from a corrupt
 *   row;
 *
 * - A NON-envelope value is a row written before the class was marked. It stays readable while the
 *   subject is not forgotten, so gradual adoption never redacts a class's history, and renders the
 *   fallback once the subject IS forgotten, keeping the post-forget read surface uniform. Honest
 *   limit, documented rather than hidden: such a cleartext row was never encrypted, so destroying
 *   the key does not erase it from the STORE, only from every read through this codec.
 *
 * Placement in the read chain: `StoredEventReader` upcasts BEFORE deserializing, so upcasters see
 * personal fields as opaque envelopes; they may move or rename one, never transform its cleartext.
 * That order is what makes the compiled map sufficient: it declares the CURRENT payload shape, and
 * decrypting after upcast looks the declared keys up in exactly that shape whatever the row's age,
 * where decrypting first would need the declaration history of every past shape. Corollary: an
 * upcaster can never leak cleartext, by construction.
 *
 * The envelope is versioned, `v2:<base64 nonce>:<base64 ciphertext>` with XChaCha20-Poly1305 over
 * the JSON-encoded value and `subject NUL class NUL key` as additional data, so an envelope
 * authenticates only in its own slot: moved across subjects, classes or FIELDS it fails and
 * renders the fallback. A `v1` envelope, sealed under the subject alone, still opens; the version
 * prefix exists precisely so a binding or algorithm change is an envelope upcast, never a data
 * migration. Honest residual: an old envelope of the SAME subject, class and key can still be
 * replayed across time, since a row position is knowledge this codec does not have.
 *
 * The generation vocabulary is a CLOSED list with no structural ceiling: one write generation at a
 * time in `SEAL_PREFIX`, every readable generation enumerated in `ENVELOPE_PREFIXES`, and a future
 * `v3` is three edits in this one file, the accepted prefix, its AAD arm in `open()`, then the
 * seal bump. The one sequencing law of a bump: readers learn a generation BEFORE writers emit it.
 * `isEnvelope()` is strict so a legitimate value merely starting with a version prefix survives
 * unredacted, and the flip side is that an UNKNOWN generation reads as cleartext passthrough,
 * raw ciphertext reaching the fold; in the monorepo both sides ship in one commit, but split
 * consumers make that ordering a release note of the bump, the same obligation family as an
 * event upcast. Prior generations stay readable forever.
 *
 * The key lookup is one store round trip per marked message BY DESIGN: the store refuses caching
 * so a forget takes effect immediately; the decision and its revisit condition are recorded on
 * `DbalCipherKeyStore`, and the one skip taken here is the row carrying NONE of the declared keys,
 * where neither decryption nor the forgotten-subject redaction has anything to do.
 *
 * @see \Storm\Message\Attribute\Personal the declaration this map is compiled from
 * @see \Storm\Chronicler\Record\StoredEventReader the upcast-then-deserialize order relied on above
 */
final readonly class CipheringMessageSerializer implements MessageSerializer
{
    /** What seal() writes: the slot-bound AAD generation. */
    private const string SEAL_PREFIX = 'v2';

    /** What open() accepts: `v1` rows, subject-only AAD, remain readable forever. */
    private const array ENVELOPE_PREFIXES = ['v1', 'v2'];

    public function __construct(
        private MessageSerializer $inner,
        private CipherKeyStore $keys,
        /**
         * The compiled `storm.personal_data` map: class => {subject, keys, fallbacks}, baked from
         * every `#[Personal]` declaration at container build; zero reflection at runtime.
         *
         * @var array<class-string, array{subject: string, keys: list<string>, fallbacks: array<string, scalar|null>}>
         */
        private array $map,
    ) {}

    /**
     * {@inheritDoc}
     *
     * For a marked class, every declared payload key is then encrypted with the subject's key,
     * issued on first use.
     *
     * @throws SerializationExceptionContract also when a marked class's payload misses its subject
     *                                        key or a declared personal key, or a personal value
     *                                        cannot be JSON-encoded for encryption
     * @throws SubjectForgotten when the payload's subject is tombstoned; issuing a key would
     *                          silently resurrect a forgotten identity
     * @throws RandomException when the entropy source cannot supply a nonce; infrastructure,
     *                         surfaced loud
     */
    public function serialize(Message $message): array
    {
        $data = $this->inner->serialize($message);

        // the inner gate guarantees the header names the wrapped class; the guard is the same belt
        // the read side wears, a decorator typed on the interface never trusting a composition fact
        // the type cannot express
        $class = $data['header'][Header::MessageType->value] ?? null;
        if (! is_string($class)) {
            throw SerializationException::missingField(Header::MessageType->value);
        }

        $declared = $this->map[$class] ?? null;

        if ($declared === null) {
            return $data;
        }

        $subject = $this->subjectOf($declared['subject'], $data['content']);

        if ($subject === null) {
            // fail-fast write gate: a marked event MUST carry its subject; without it the fields
            // would be stored in clear, or encrypted under nobody, both silent conformance holes
            throw SerializationException::missingPersonalSubject($class, $declared['subject']);
        }

        // the declaration gate runs BEFORE the key store is touched: a drifted declaration must
        // report drift, never "forgotten subject", and a write that will refuse anyway must not
        // mint a durable key row for its subject
        foreach ($declared['keys'] as $key) {
            if (! array_key_exists($key, $data['content'])) {
                // a declared key the payload no longer emits is declaration drift, and a silent
                // skip would store whatever replaced it in clear
                throw SerializationException::missingPersonalKey($class, $key);
            }
        }

        $material = $this->keys->issue($subject);

        foreach ($declared['keys'] as $key) {
            $data['content'][$key] = $this->seal($data['content'][$key], $material, $subject, $class, $key);
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     *
     * For a marked class, every declared payload key is first rendered back, decrypted or replaced
     * by its declared fallback, so `fromPayload()` always receives readable values, never an
     * envelope.
     */
    public function deserialize(array $data): Message
    {
        $type = $data['header'][Header::MessageType->value] ?? null;
        $declared = is_string($type) ? ($this->map[$type] ?? null) : null;

        // @phpstan-ignore nullCoalesce.offset (the @param shape guarantees `content`; the ?? is the runtime belt-and-suspenders for a shape-violating caller; pass through, the inner gates own the error contract)
        $content = $data['content'] ?? null;

        // an unmarked or headerless input passes through untouched
        if ($declared === null || ! is_array($content)) { // @phpstan-ignore function.alreadyNarrowedType
            return $this->inner->deserialize($data);
        }

        $subject = $this->subjectOf($declared['subject'], $content);

        // the one safe skip: a shape carrying NONE of the declared keys has nothing to decrypt AND
        // nothing the forgotten-subject rule could redact. With ANY key present the store lookup is
        // unconditional ON PURPOSE: even an all-cleartext row must render fallbacks once its
        // subject is forgotten, so skipping on "no envelope" would present a forgotten identity in
        // clear
        if (! array_any($declared['keys'], static fn (string $key): bool => array_key_exists($key, $content))) {
            return $this->inner->deserialize($data);
        }

        $material = $subject === null ? null : $this->keys->keyFor($subject);
        // the tombstone check runs only when no material came back: it is what tells "forgotten",
        // where cleartext renders fallbacks too, from "never issued", where cleartext stays readable
        $destroyed = $material === null && $subject !== null && $this->keys->isDestroyed($subject);

        foreach ($declared['keys'] as $key) {
            if (! array_key_exists($key, $content)) {
                continue; // an older shape without the key; the upcasters own shape migration
            }

            $value = $content[$key];

            if (is_string($value) && $this->isEnvelope($value)) {
                // an envelope NEVER passes through: cleartext, or the fallback; raw ciphertext is
                // not a value any fold should see
                $content[$key] = $material !== null
                    ? $this->open($value, $material, (string) $subject, $type, $key, $declared['fallbacks'])
                    : $declared['fallbacks'][$key];
            } elseif ($destroyed) {
                $content[$key] = $declared['fallbacks'][$key];
            }
        }

        return $this->inner->deserialize(['header' => $data['header'], 'content' => $content]);
    }

    /**
     * The payload's subject id, normalized: a non-blank string or int payload value, or null when
     * absent or degenerate; the WRITE path refuses null loud, the READ path degrades to fallbacks.
     *
     * @param  array<string, mixed>  $content
     */
    private function subjectOf(string $subjectKey, array $content): ?string
    {
        $value = $content[$subjectKey] ?? null;

        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Encrypt one payload value into a versioned envelope: XChaCha20-Poly1305 over the JSON-encoded
     * value, a fresh random nonce per field, and the `subject NUL class NUL key` tuple as
     * additional data so an envelope authenticates only in its own slot; cut-and-pasted onto
     * another subject's row, another class, or another FIELD of the same subject, it fails instead
     * of decrypting.
     *
     * @throws SerializationExceptionContract when the value cannot be JSON-encoded; the write
     *                                        path's loud refusal, mirroring the codec's own
     * @throws RandomException when the entropy source cannot supply the nonce; infrastructure,
     *                         surfaced loud because a write must never settle for a weaker nonce
     */
    private function seal(mixed $value, string $material, string $subject, string $class, string $key): string
    {
        try {
            $clear = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw SerializationException::payloadSerializationFailed($class, $e);
        }

        try {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($clear, self::aad($subject, $class, $key), $nonce, $material);
        } catch (SodiumException $e) {
            // reachable only through a key store handing back wrong-length material: a broken
            // implementation, not data; the write path surfaces it rather than storing cleartext
            throw SerializationException::payloadSerializationFailed($class, $e);
        }

        return self::SEAL_PREFIX.':'.base64_encode($nonce).':'.base64_encode($ciphertext);
    }

    /**
     * Decrypt one envelope, TOTAL: the cleartext value, or the key's declared fallback when the
     * envelope does not authenticate or its cleartext is not decodable; never a throw, never the
     * ciphertext. The additional data follows the envelope's own generation: `v1` rows were sealed
     * under the subject alone and stay readable, `v2` binds the full slot.
     *
     * @param  array<string, scalar|null>  $fallbacks
     */
    private function open(string $envelope, string $material, string $subject, string $class, string $key, array $fallbacks): mixed
    {
        // no explode limit needed: isEnvelope() already proved exactly three strict-base64 segments
        [$version, $nonce, $ciphertext] = explode(':', $envelope);

        try {
            $clear = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                (string) base64_decode($ciphertext, true),
                $version === 'v1' ? $subject : self::aad($subject, $class, $key),
                (string) base64_decode($nonce, true),
                $material,
            );
        } catch (SodiumException) {
            return $fallbacks[$key];
        }

        if ($clear === false) {
            return $fallbacks[$key];
        }

        try {
            return json_decode($clear, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $fallbacks[$key];
        }
    }

    /**
     * The slot an envelope is bound to: `subject NUL class NUL key`, NUL-joined because none of the
     * three may contain it, so the tuple can never be forged by shifting bytes between components.
     */
    private static function aad(string $subject, string $class, string $key): string
    {
        return $subject."\0".$class."\0".$key;
    }

    /**
     * Whether a stored string is one of OUR envelopes, structurally: a known version prefix, a
     * strict base64 nonce of the exact XChaCha20 length, a strict base64 ciphertext. Anything else
     * is a cleartext value from before the class was marked and passes through; the strictness is
     * what makes a legitimate value that merely STARTS with a version prefix survive unredacted.
     */
    private function isEnvelope(string $value): bool
    {
        $parts = explode(':', $value);

        if (count($parts) !== 3 || ! in_array($parts[0], self::ENVELOPE_PREFIXES, true)) {
            return false;
        }

        $nonce = base64_decode($parts[1], true);

        return $nonce !== false
            && strlen($nonce) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            && $parts[2] !== ''
            && base64_decode($parts[2], true) !== false;
    }
}
