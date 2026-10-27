<?php

declare(strict_types=1);

use App\Gateways\FakePaymentGateway;
use App\Gateways\PaymentGateway;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Shopwise\Kit\Auth\KeySource;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\OutboxMessage;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/** The checkout of the contract examples (payments.request-payment / payments.refund-payment). */
const CHECKOUT_ID = '01JBCDEFGHJKMNPQRSTVWXYZ40';

/**
 * Payment attributes matching the contract examples' checkout (refund-payment checks customer, amount and currency).
 *
 * @return array{checkout_id: string, customer_id: string, amount: int, currency: string}
 */
function exampleCheckout(): array
{
    return ['checkout_id' => CHECKOUT_ID, 'customer_id' => '01JBCDEFGHJKMNPQRSTVWXYZ11', 'amount' => 5220, 'currency' => 'USD'];
}

/**
 * Loads the contract example envelope for an event or command (shopwise-contracts/examples), optionally
 * overriding data fields and the envelope id (a redelivery keeps the id, a re-sent command gets a new one).
 *
 * @param  array<string, mixed>  $data
 */
function contractExample(string $typeAndVersion, array $data = [], ?string $id = null): Envelope
{
    $json = file_get_contents(base_path("vendor/shopwise/contracts/examples/{$typeAndVersion}.json"));
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $original */
    $original = $payload['data'];
    $payload['data'] = array_replace($original, $data);
    $payload['id'] = $id ?? $payload['id'];

    return Envelope::fromArray($payload);
}

/**
 * Binds a fresh fake gateway the app will use, and returns it for asserting calls.
 */
function fakeGateway(): FakePaymentGateway
{
    $gateway = new FakePaymentGateway;
    app()->instance(PaymentGateway::class, $gateway);

    return $gateway;
}

/**
 * Authorization header with an identity-style access token. The test key replaces identity's JWKS,
 * so no HTTP call is made.
 *
 * @param  list<string>  $roles
 * @return array{Authorization: string}
 */
function bearer(array $roles = ['customer', 'admin'], string $subject = '01JBCDEFGHJKMNPQRSTVWXYZ11'): array
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
        'sub' => $subject,
        'email' => 'customer@shopwise.test',
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
 * A Stripe event body and its Stripe-Signature header, signed like Stripe does with the test webhook secret.
 *
 * @param  array<string, mixed>  $object  data.object of the event
 * @return array{0: string, 1: string}
 */
function stripeEvent(string $type, array $object, string $id = 'evt_test_1', ?int $timestamp = null): array
{
    $payload = json_encode([
        'id' => $id,
        'object' => 'event',
        'type' => $type,
        'api_version' => '2025-09-30.clover',
        'created' => time(),
        'data' => ['object' => $object],
    ], JSON_THROW_ON_ERROR);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", (string) config('services.stripe.webhook_secret'));

    return [$payload, "t={$timestamp},v1={$signature}"];
}

/**
 * A PaymentIntent object as Stripe sends it in webhooks.
 *
 * @param  array<string, mixed>|null  $lastPaymentError
 * @return array<string, mixed>
 */
function stripeIntent(string $id, string $status, ?array $lastPaymentError = null): array
{
    return [
        'id' => $id,
        'object' => 'payment_intent',
        'amount' => 5220,
        'currency' => 'usd',
        'status' => $status,
        'last_payment_error' => $lastPaymentError,
    ];
}
