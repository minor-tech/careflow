<?php

namespace Tests\Feature\Services;

use App\Services\AfricasTalkingGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesSmsGateway;
use Tests\TestCase;

class AfricasTalkingGatewayTest extends TestCase
{
    use FakesSmsGateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();
    }

    private function gateway(): AfricasTalkingGateway
    {
        return app(AfricasTalkingGateway::class);
    }

    public function test_sends_the_message_to_the_messaging_endpoint_with_the_credentials_and_sender(): void
    {
        $this->gatewayAccepts();

        $result = $this->gateway()->send('+254712345678', 'Hello there', 'UPENDO');

        $this->assertTrue($result->accepted);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.africastalking.com/version1/messaging'
            && $request->method() === 'POST'
            && $request->hasHeader('apiKey', 'test-api-key')
            && $request->hasHeader('Accept', 'application/json')
            && $request->data() === ['username' => 'sandbox', 'to' => '+254712345678', 'message' => 'Hello there', 'from' => 'UPENDO']);
    }

    public function test_leaves_out_the_sender_when_there_is_none(): void
    {
        $this->gatewayAccepts();

        $this->gateway()->send('+254712345678', 'Hello', null);
        $this->gateway()->send('+254712345678', 'Hello', '');

        Http::assertSent(fn (Request $request) => ! array_key_exists('from', $request->data()));
    }

    public function test_can_be_pointed_at_the_sandbox(): void
    {
        config(['services.africastalking.base_url' => 'https://api.sandbox.africastalking.com/']);
        $this->gatewayAccepts();

        $this->gateway()->send('+254712345678', 'Hello');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.sandbox.africastalking.com/version1/messaging');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function acceptedCodes(): array
    {
        return ['processed' => [100], 'sent' => [101], 'queued' => [102]];
    }

    #[DataProvider('acceptedCodes')]
    public function test_a_processed_sent_or_queued_message_counts_as_accepted(int $code): void
    {
        $this->gatewayAccepts($code);

        $result = $this->gateway()->send('+254712345678', 'Hello');

        $this->assertTrue($result->accepted);
        $this->assertStringContainsString('ATXid_1', $result->response);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function refusedCodes(): array
    {
        return [
            'invalid sender id' => [402, 'InvalidSenderId'],
            'invalid phone number' => [403, 'InvalidPhoneNumber'],
            'insufficient balance' => [405, 'InsufficientBalance'],
            'blacklisted' => [406, 'UserInBlackList'],
        ];
    }

    #[DataProvider('refusedCodes')]
    public function test_a_message_the_gateway_took_but_refused_is_a_failure_that_says_why(int $code, string $status): void
    {
        Http::fake(['*' => Http::response(['SMSMessageData' => [
            'Message' => 'Sent to 0/1 Total Cost: 0',
            'Recipients' => [['statusCode' => $code, 'number' => '+254712345678', 'status' => $status]],
        ]], 201)]);

        $result = $this->gateway()->send('+254712345678', 'Hello', 'NOPE');

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString($status, $result->response);
    }

    public function test_an_answer_with_no_recipients_is_a_failure(): void
    {
        Http::fake(['*' => Http::response(['SMSMessageData' => ['Message' => 'Invalid Sender Id', 'Recipients' => []]], 201)]);

        $result = $this->gateway()->send('+254712345678', 'Hello');

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('Invalid Sender Id', $result->response);
    }

    public function test_a_wrong_api_key_is_a_failure_with_the_gateways_own_words_and_is_not_retried(): void
    {
        $this->gatewayRejects(401, 'The supplied authentication is invalid');

        $result = $this->gateway()->send('+254712345678', 'Hello');

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('HTTP 401', $result->response);
        $this->assertStringContainsString('The supplied authentication is invalid', $result->response);
        Http::assertSentCount(1);
    }

    public function test_a_server_error_is_a_failure(): void
    {
        $this->gatewayRejects(500, '');

        $result = $this->gateway()->send('+254712345678', 'Hello');

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('HTTP 500', $result->response);
        $this->assertStringContainsString('(no response body)', $result->response);
    }

    public function test_an_unreachable_gateway_is_retried_a_few_times_then_reported_as_a_failure_not_thrown(): void
    {
        Sleep::fake();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: timed out');
        });

        $result = $this->gateway()->send('+254712345678', 'Hello');

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('Could not reach the SMS gateway', $result->response);
        $this->assertStringContainsString('timed out', $result->response);
        $this->assertSame(3, $attempts);
    }

    public function test_a_connection_that_recovers_on_a_retry_succeeds_and_sends_once(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection()
            ->push(['SMSMessageData' => ['Recipients' => [['statusCode' => 101, 'status' => 'Success']]]], 201)]);

        $result = $this->gateway()->send('+254712345678', 'Hello');

        $this->assertTrue($result->accepted);
    }

    public function test_without_credentials_nothing_is_sent_and_it_says_so(): void
    {
        config(['services.africastalking.api_key' => null]);

        $result = $this->gateway()->send('+254712345678', 'Hello');

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString("isn't set up", $result->response);
        $this->assertStringContainsString('AFRICASTALKING_API_KEY', $result->response);
        Http::assertNothingSent();
    }

    public function test_the_api_key_never_appears_in_what_is_kept(): void
    {
        $this->gatewayRejects(401, 'The supplied authentication is invalid');

        $this->assertStringNotContainsString('test-api-key', $this->gateway()->send('+254712345678', 'Hello')->response);

        $this->gatewayAccepts();

        $this->assertStringNotContainsString('test-api-key', $this->gateway()->send('+254712345678', 'Hello')->response);
    }

    public function test_a_very_long_answer_is_cut_down_before_it_is_kept(): void
    {
        $this->gatewayRejects(500, str_repeat('x', 10000));

        $this->assertLessThan(2200, strlen($this->gateway()->send('+254712345678', 'Hello')->response));
    }

    public function test_the_default_sender_id_is_careflow(): void
    {
        $this->assertSame('CAREFLOW', config('services.africastalking.default_sender_id'));
    }
}
