<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Actions;

use App\Domain\Webhook\Exceptions\WebhookTargetRefused;
use App\Domain\Webhook\Models\WebhookDelivery;
use App\Domain\Webhook\Support\WebhookSigner;
use App\Domain\Webhook\Support\WebhookTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * ONE attempt: vet the address, sign, send, write down what happened.
 *
 * Returns whether the receiver accepted it and never throws — a failure is a
 * fact to record and retry, and the retry schedule is `DeliverWebhook`'s.
 */
final class AttemptDelivery
{
    public function __construct(
        private readonly WebhookTarget $target,
        private readonly WebhookSigner $signer,
    ) {}

    public function handle(WebhookDelivery $delivery): bool
    {
        $delivery->loadMissing('endpoint');
        $endpoint = $delivery->endpoint;
        $started = hrtime(true);
        $accepted = false;

        $fields = [
            'attempts' => $delivery->attempts + 1,
            'last_attempt_at' => now(),
            'next_attempt_at' => null,
            'response_status' => null,
            'response_body' => null,
            'error' => null,
        ];

        try {
            // Again, at send time: the host may have been re-pointed since the
            // endpoint was saved. See WebhookTarget.
            $vetted = $this->target->vet($endpoint->url);

            $response = Http::timeout((int) config('orbito.webhooks.timeout_seconds'))
                ->connectTimeout(5)
                // A redirect is how a public URL is bounced onto an internal
                // one, after the check has passed. It is a failure, not a hop.
                ->withoutRedirecting()
                // Connect to the address that was vetted, not to whatever a
                // second lookup returns.
                ->withOptions(['curl' => [CURLOPT_RESOLVE => [$this->pin($vetted)]]])
                ->withHeaders([
                    'User-Agent' => 'Orbito-Webhooks/1',
                    WebhookSigner::HEADER => $this->signer->header($endpoint->secret, $delivery->body, now()->getTimestamp()),
                    'Orbito-Event' => $delivery->topic,
                    'Orbito-Event-Id' => $delivery->event_id,
                    'Orbito-Delivery' => $delivery->uuid,
                ])
                ->withBody($delivery->body, 'application/json')
                ->post($endpoint->url);

            $fields['response_status'] = $response->status();
            $fields['response_body'] = Str::limit($response->body(), 2000, '…');
            $accepted = $response->successful();

            if (! $accepted) {
                $fields['error'] = $response->redirect()
                    ? 'The receiver redirected, and redirects are not followed.'
                    : "The receiver answered {$response->status()}.";
            }
        } catch (WebhookTargetRefused $e) {
            $fields['error'] = $e->getMessage();
        } catch (ConnectionException $e) {
            $fields['error'] = Str::limit('Could not connect: '.$e->getMessage(), 490, '…');
        } catch (Throwable $e) {
            report($e);
            $fields['error'] = 'Something went wrong on our side while sending.';
        }

        $fields['duration_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);
        $delivery->forceFill($fields)->save();

        return $accepted;
    }

    /** @param  array{host: string, port: int, ip: string}  $vetted */
    private function pin(array $vetted): string
    {
        $ip = str_contains($vetted['ip'], ':') ? "[{$vetted['ip']}]" : $vetted['ip'];

        return "{$vetted['host']}:{$vetted['port']}:{$ip}";
    }
}
