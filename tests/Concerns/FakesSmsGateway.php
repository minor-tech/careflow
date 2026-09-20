<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Sets up the SMS gateway for a test: credentials configured, and no real
 * request ever leaving the machine.
 */
trait FakesSmsGateway
{
    protected function configureGateway(): void
    {
        config(['services.africastalking' => [
            'username' => 'sandbox',
            'api_key' => 'test-api-key',
            'base_url' => 'https://api.africastalking.com',
            'default_sender_id' => 'CAREFLOW',
        ]]);

        Http::preventStrayRequests();
    }

    /**
     * The gateway accepts every message.
     */
    protected function gatewayAccepts(int $statusCode = 101): void
    {
        Http::fake(['*' => Http::response([
            'SMSMessageData' => [
                'Message' => 'Sent to 1/1 Total Cost: KES 0.8000',
                'Recipients' => [['statusCode' => $statusCode, 'number' => '+254712345678', 'cost' => 'KES 0.8000', 'status' => 'Success', 'messageId' => 'ATXid_1']],
            ],
        ], 201)]);
    }

    /**
     * The gateway refuses (a wrong API key, say).
     */
    protected function gatewayRejects(int $httpStatus = 401, string $body = 'The supplied authentication is invalid'): void
    {
        Http::fake(['*' => Http::response($body, $httpStatus)]);
    }
}
