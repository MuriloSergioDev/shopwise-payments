<?php

declare(strict_types=1);

use App\Data\ChargeData;
use App\Data\IntentResult;
use App\Enums\FailureReason;
use App\Enums\IntentStatus;
use App\Gateways\ChargeOutcomeUnknown;
use App\Gateways\StripePaymentGateway;
use Stripe\ApiRequestor;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

/**
 * Stands in for Stripe's HTTP layer: records requests and answers with queued [status, body] responses.
 */
final class StripeHttpStub implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>}> */
    public array $requests = [];

    /** @param list<array{0: int, 1: array<string, mixed>}> $responses */
    public function __construct(private array $responses) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];
        [$status, $body] = array_shift($this->responses) ?? [500, []];

        return [json_encode($body, JSON_THROW_ON_ERROR), $status, ['request-id' => 'req_test']];
    }
}

function stripeGateway(StripeHttpStub $http): StripePaymentGateway
{
    ApiRequestor::setHttpClient($http);

    return new StripePaymentGateway(new StripeClient(['api_key' => 'sk_test_placeholder', 'max_network_retries' => 0]));
}

afterEach(function (): void {
    ApiRequestor::setHttpClient(null);
});

$charge = new ChargeData(amount: 5220, currency: 'USD', paymentMethodId: 'pm_card_visa', idempotencyKey: 'checkout-01JB-pay', metadata: ['checkoutId' => '01JB']);

it('creates and confirms the intent without redirects, with the idempotency key', function () use ($charge): void {
    $http = new StripeHttpStub([[200, stripeIntent('pi_1', 'succeeded')]]);

    $result = stripeGateway($http)->charge($charge);

    expect($result->intentId)->toBe('pi_1')
        ->and($result->status)->toBe(IntentStatus::Succeeded)
        ->and($http->requests[0]['method'])->toBe('post')
        ->and($http->requests[0]['url'])->toEndWith('/v1/payment_intents')
        ->and($http->requests[0]['headers'])->toContain('Idempotency-Key: checkout-01JB-pay')
        ->and($http->requests[0]['params'])->toMatchArray([
            'amount' => 5220,
            'currency' => 'usd',
            'payment_method' => 'pm_card_visa',
            'confirm' => 'true',
            'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
            'metadata' => ['checkoutId' => '01JB'],
        ]);
});

it('turns a 402 decline into a failed result with the reason and message', function () use ($charge): void {
    $error = ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.'];
    $http = new StripeHttpStub([[402, ['error' => [...$error, 'payment_intent' => stripeIntent('pi_2', 'requires_payment_method', $error)]]]]);

    $result = stripeGateway($http)->charge($charge);

    expect($result->intentId)->toBe('pi_2')
        ->and($result->status)->toBe(IntentStatus::Failed)
        ->and($result->failureReason)->toBe(FailureReason::InsufficientFunds)
        ->and($result->failureMessage)->toBe('Your card has insufficient funds.');
});

it('reports how an intent ended when Stripe refuses to cancel it', function (): void {
    $http = new StripeHttpStub([
        [400, ['error' => ['type' => 'invalid_request_error', 'code' => 'payment_intent_unexpected_state', 'message' => 'already succeeded']]],
        [200, stripeIntent('pi_3', 'succeeded')],
    ]);

    expect(stripeGateway($http)->cancel('pi_3')->status)->toBe(IntentStatus::Succeeded)
        ->and(array_column($http->requests, 'url'))->sequence(
            fn ($url) => $url->toEndWith('/v1/payment_intents/pi_3/cancel'),
            fn ($url) => $url->toEndWith('/v1/payment_intents/pi_3'),
        );
});

it('refunds the intent with the refund idempotency key', function (): void {
    $http = new StripeHttpStub([[200, ['id' => 're_1', 'object' => 'refund', 'status' => 'succeeded']]]);

    expect(stripeGateway($http)->refund('pi_4', 'checkout-01JB-refund'))->toBe('re_1')
        ->and($http->requests[0]['params'])->toBe(['payment_intent' => 'pi_4'])
        ->and($http->requests[0]['headers'])->toContain('Idempotency-Key: checkout-01JB-refund');
});

it('maps intents that are not final to pending', function (): void {
    $http = new StripeHttpStub([[200, stripeIntent('pi_5', 'processing')]]);

    expect(stripeGateway($http)->retrieve('pi_5')->status)->toBe(IntentStatus::Pending);
});

it('keeps the client secret and creation time of an intent that needs 3-D Secure', function () use ($charge): void {
    $http = new StripeHttpStub([[200, [...stripeIntent('pi_6', 'requires_action'), 'client_secret' => 'pi_6_secret_abc', 'created' => 1793000000]]]);

    $result = stripeGateway($http)->charge($charge);

    expect($result->status)->toBe(IntentStatus::RequiresAction)
        ->and($result->clientSecret)->toBe('pi_6_secret_abc')
        ->and($result->createdAt)->toBe(1793000000);
});

it('reports an unknown outcome when Stripe answers 5xx', function () use ($charge): void {
    $http = new StripeHttpStub([[500, ['error' => ['type' => 'api_error', 'message' => 'Something went wrong']]]]);

    expect(fn (): IntentResult => stripeGateway($http)->charge($charge))->toThrow(ChargeOutcomeUnknown::class);
});

it('rethrows a request Stripe refused, since nothing was charged', function () use ($charge): void {
    $http = new StripeHttpStub([[400, ['error' => ['type' => 'invalid_request_error', 'message' => 'No such PaymentMethod']]]]);

    expect(fn (): IntentResult => stripeGateway($http)->charge($charge))->toThrow(InvalidRequestException::class);
});

it('finds the intent of a checkout by its metadata', function () use ($charge): void {
    $http = new StripeHttpStub([
        [200, ['object' => 'search_result', 'data' => [stripeIntent('pi_7', 'succeeded')], 'has_more' => false, 'url' => '/v1/payment_intents/search']],
        [200, ['object' => 'search_result', 'data' => [], 'has_more' => false, 'url' => '/v1/payment_intents/search']],
    ]);
    $gateway = stripeGateway($http);

    expect($gateway->find($charge)?->intentId)->toBe('pi_7')
        ->and($gateway->find($charge))->toBeNull()
        ->and($http->requests[0]['url'])->toContain('/v1/payment_intents/search')
        ->and($http->requests[0]['params'])->toBe(['query' => "metadata['checkoutId']:'01JB'", 'limit' => 1]);
});

it('reads the newest refund of an intent', function (): void {
    $http = new StripeHttpStub([[200, ['object' => 'list', 'data' => [['id' => 're_9', 'object' => 'refund']], 'has_more' => false, 'url' => '/v1/refunds']]]);

    expect(stripeGateway($http)->latestRefundId('pi_8'))->toBe('re_9')
        ->and($http->requests[0]['params'])->toBe(['payment_intent' => 'pi_8', 'limit' => 1]);
});
