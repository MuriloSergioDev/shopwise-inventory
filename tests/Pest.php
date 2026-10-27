<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Shopwise\Kit\Auth\KeySource;
use Shopwise\Kit\Messaging\DispatchResult;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageDispatcher;
use Shopwise\Kit\Messaging\OutboxMessage;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/**
 * Loads the contract example envelope for an event (shopwise-contracts/examples), optionally overriding data fields.
 *
 * @param  array<string, mixed>  $data
 */
function contractExample(string $typeAndVersion, array $data = []): Envelope
{
    $json = file_get_contents(base_path("vendor/shopwise/contracts/examples/{$typeAndVersion}.json"));
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $original */
    $original = $payload['data'];
    $payload['data'] = array_replace($original, $data);

    return Envelope::fromArray($payload);
}

/**
 * Authorization header with an identity-style access token. The test key replaces identity's JWKS,
 * so no HTTP call is made.
 *
 * @param  list<string>  $roles
 * @return array{Authorization: string}
 */
function bearer(array $roles = ['customer', 'admin']): array
{
    static $privateKey = null;
    static $publicKey = null;
    if ($privateKey === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $publicKey = openssl_pkey_get_details($key)['key'];
    }

    app()->instance(KeySource::class, new readonly class($publicKey) implements KeySource
    {
        public function __construct(private string $publicKey) {}

        public function keys(bool $refresh = false): array
        {
            return ['test' => new Key($this->publicKey, 'RS256')];
        }
    });

    $token = JWT::encode([
        'iss' => 'shopwise-identity',
        'sub' => '01JBCDEFGHJKMNPQRSTVWXYZ99',
        'email' => 'admin@shopwise.test',
        'roles' => $roles,
        'iat' => time(),
        'exp' => time() + 900,
    ], $privateKey, 'RS256', 'test');

    return ['Authorization' => "Bearer {$token}"];
}

/**
 * The `data` payloads recorded in the outbox for one event type, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function recordedEvents(string $type): array
{
    return OutboxMessage::query()->where('type', $type)->orderBy('id')->get()
        ->map(fn (OutboxMessage $message): mixed => $message->envelope['data'])->values()->all();
}

/**
 * A command or event envelope as checkout or catalog would send it: the contract example's data with overrides,
 * in a fresh envelope (new id), so the dedup table does not swallow it.
 *
 * @param  array<string, mixed>  $data
 */
function message(string $typeAndVersion, array $data = []): Envelope
{
    return Envelope::fromArray([...contractExample($typeAndVersion, $data)->toArray(), 'id' => (string) Str::ulid()]);
}

/**
 * Hands a message to the kit's dispatcher as the consumer would (topic = type).
 *
 * @param  array<string, mixed>  $data
 */
function dispatchMessage(string $typeAndVersion, array $data = []): DispatchResult
{
    $envelope = message($typeAndVersion, $data);

    return app(MessageDispatcher::class)->dispatch($envelope, $envelope->type);
}

/**
 * Sends checkout's inventory.reserve-stock command.
 *
 * @param  list<array{sku: string, quantity: int}>  $lines
 */
function reserve(array $lines, string $checkoutId = '01JBCDEFGHJKMNPQRSTVWXYZ40'): DispatchResult
{
    return dispatchMessage('inventory.reserve-stock.v1', ['checkoutId' => $checkoutId, 'lines' => $lines]);
}
