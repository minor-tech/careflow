<?php

namespace App\Services;

use App\Support\SmsResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one SMS through Africa's Talking. It never throws: whatever goes wrong
 * (no credentials, no network, a rejected key or sender ID) comes back as a
 * failed SmsResult that says what happened.
 */
class AfricasTalkingGateway
{
    /**
     * Per-recipient status codes meaning the message was taken: 100 processed,
     * 101 sent, 102 queued. Anything else (402 invalid sender ID, 403 invalid
     * phone number, 405 insufficient balance, ...) is a failure.
     *
     * @var list<int>
     */
    private const ACCEPTED_CODES = [100, 101, 102];

    private const MAX_STORED_RESPONSE = 2000;

    public function isConfigured(): bool
    {
        return filled(config('services.africastalking.username')) && filled(config('services.africastalking.api_key'));
    }

    /**
     * @param  string  $to  A phone number in international form, e.g. +254712345678
     */
    public function send(string $to, string $message, ?string $senderId = null): SmsResult
    {
        if (! $this->isConfigured()) {
            return SmsResult::failed("The SMS gateway isn't set up: AFRICASTALKING_USERNAME and AFRICASTALKING_API_KEY are missing.");
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->withHeaders(['apiKey' => (string) config('services.africastalking.api_key')])
                ->connectTimeout(5)
                ->timeout(15)
                // Only a failure to connect is retried: nothing was received, so it can't send twice.
                ->retry(3, 300, fn (Throwable $exception) => $exception instanceof ConnectionException, throw: false)
                ->post(rtrim((string) config('services.africastalking.base_url'), '/').'/version1/messaging', array_filter([
                    'username' => config('services.africastalking.username'),
                    'to' => $to,
                    'message' => $message,
                    'from' => $senderId,
                ], filled(...)));
        } catch (ConnectionException $exception) {
            return SmsResult::failed('Could not reach the SMS gateway: '.$exception->getMessage());
        }

        return $this->interpret($response);
    }

    private function interpret(Response $response): SmsResult
    {
        $body = Str::limit($response->body(), self::MAX_STORED_RESPONSE, '...');

        if (! $response->successful()) {
            return SmsResult::failed("The gateway answered HTTP {$response->status()}: ".($body !== '' ? $body : '(no response body)'));
        }

        $recipients = (array) $response->json('SMSMessageData.Recipients', []);

        foreach ($recipients as $recipient) {
            if (in_array((int) ($recipient['statusCode'] ?? 0), self::ACCEPTED_CODES, true)) {
                return SmsResult::accepted($body);
            }
        }

        $why = $recipients[0]['status'] ?? $response->json('SMSMessageData.Message') ?? 'the gateway did not say why';

        return SmsResult::failed("The gateway did not accept the message ({$why}). Response: {$body}");
    }
}
