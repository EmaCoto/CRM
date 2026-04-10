<?php

namespace Webkul\Admin\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZadarmaService
{
    /**
     * API base URL.
     */
    protected string $baseUrl = 'https://api.zadarma.com';

    /**
     * Return whether Zadarma call flow is ready to be used.
     */
    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && filled(core()->getConfigData('general.telephony.zadarma.api_key'))
            && filled(core()->getConfigData('general.telephony.zadarma.api_secret'))
            && filled(core()->getConfigData('general.telephony.zadarma.source'));
    }

    /**
     * Return whether the integration has been enabled in configuration.
     */
    public function isEnabled(): bool
    {
        return (bool) core()->getConfigData('general.telephony.zadarma.enabled');
    }

    /**
     * Start a real callback request in Zadarma.
     */
    public function requestCallback(string $phoneNumber): array
    {
        return $this->request('GET', '/v1/request/callback/', array_filter([
            'from'      => $this->source(),
            'to'        => $this->normalizePhone($phoneNumber),
            'sip'       => $this->sip(),
            'predicted' => $this->usePredictedCallback() ? 1 : null,
        ], fn ($value) => filled($value)));
    }

    /**
     * Ensure the outbound call webhook is configured in Zadarma.
     */
    public function ensureCallWebhookConfigured(): void
    {
        if (! $this->shouldAutoSyncWebhook()) {
            return;
        }

        $webhookUrl = $this->getWebhookUrl();

        if (! $webhookUrl) {
            return;
        }

        $currentSettings = $this->request('GET', '/v1/pbx/callinfo/');

        if (($currentSettings['url'] ?? null) !== $webhookUrl) {
            $this->request('POST', '/v1/pbx/callinfo/url/', [
                'url' => $webhookUrl,
            ]);
        }

        $notifications = $currentSettings['notifications'] ?? [];

        $desiredNotifications = [
            'notify_out_start' => 'true',
            'notify_answer'    => 'true',
            'notify_out_end'   => 'true',
        ];

        $needsUpdate = false;

        foreach ($desiredNotifications as $notification => $value) {
            if (($notifications[$notification] ?? null) !== $value) {
                $needsUpdate = true;

                break;
            }
        }

        if ($needsUpdate) {
            $this->request('POST', '/v1/pbx/callinfo/notifications/', $desiredNotifications);
        }
    }

    /**
     * Return the configured source number / extension.
     */
    public function source(): ?string
    {
        return $this->normalizePhone((string) core()->getConfigData('general.telephony.zadarma.source'));
    }

    /**
     * Return the configured SIP / PBX extension.
     */
    public function sip(): ?string
    {
        $sip = trim((string) core()->getConfigData('general.telephony.zadarma.sip'));

        return $sip !== '' ? $sip : null;
    }

    /**
     * Whether predicted callback is enabled.
     */
    public function usePredictedCallback(): bool
    {
        return (bool) core()->getConfigData('general.telephony.zadarma.predicted');
    }

    /**
     * Whether auto webhook synchronization is enabled.
     */
    public function shouldAutoSyncWebhook(): bool
    {
        return (bool) core()->getConfigData('general.telephony.zadarma.auto_sync_webhook');
    }

    /**
     * Return a public webhook URL when available.
     */
    public function getWebhookUrl(): ?string
    {
        $overrideUrl = trim((string) core()->getConfigData('general.telephony.zadarma.webhook_url'));

        if ($overrideUrl !== '') {
            return $overrideUrl;
        }

        $generatedUrl = url('/zadarma/webhooks/call-events');

        return $this->isPublicUrl($generatedUrl) ? $generatedUrl : null;
    }

    /**
     * Validate a Zadarma webhook signature.
     */
    public function isValidWebhookSignature(array $payload, ?string $signature): bool
    {
        if (! $signature) {
            return false;
        }

        $signaturePayload = match ($payload['event'] ?? null) {
            'NOTIFY_ANSWER' => ($payload['caller_id'] ?? '') . ($payload['destination'] ?? '') . ($payload['call_start'] ?? ''),
            'NOTIFY_OUT_START', 'NOTIFY_OUT_END' => ($payload['internal'] ?? '') . ($payload['destination'] ?? '') . ($payload['call_start'] ?? ''),
            'NOTIFY_RECORD' => ($payload['pbx_call_id'] ?? '') . ($payload['call_id_with_rec'] ?? ''),
            default => null,
        };

        if ($signaturePayload === null) {
            return false;
        }

        $expectedSignature = base64_encode(hash_hmac('sha1', $signaturePayload, $this->secret()));

        return hash_equals($expectedSignature, trim($signature));
    }

    /**
     * Normalize phone-like values without stripping the PBX extension format.
     */
    public function normalizePhone(?string $phone): string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return '';
        }

        return preg_replace('/[^\d+]/', '', $phone) ?: '';
    }

    /**
     * Perform an authenticated request to Zadarma.
     */
    protected function request(string $httpMethod, string $method, array $params = []): array
    {
        $params = array_filter($params, fn ($value) => ! is_null($value) && $value !== '');

        ksort($params);

        $paramsString = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
        $signature    = base64_encode(hash_hmac('sha1', $method . $paramsString . md5($paramsString), $this->secret()));

        $http = Http::withHeaders([
            'Authorization' => $this->key() . ': ' . $signature,
            'Accept'        => 'application/json',
        ]);

        $response = match (strtoupper($httpMethod)) {
            'GET'    => $http->get($this->baseUrl . $method, $params),
            'POST'   => $http->asForm()->post($this->baseUrl . $method, $params),
            'PUT'    => $http->asForm()->put($this->baseUrl . $method, $params),
            'DELETE' => $http->asForm()->delete($this->baseUrl . $method, $params),
            default  => throw new RuntimeException("Unsupported Zadarma method [$httpMethod]."),
        };

        if ($response->failed()) {
            throw new RuntimeException($response->json('message') ?: $response->body());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Invalid Zadarma response.');
        }

        if (($payload['status'] ?? null) !== 'success') {
            throw new RuntimeException($payload['message'] ?? 'Zadarma request failed.');
        }

        return $payload;
    }

    /**
     * Return the API key.
     */
    protected function key(): string
    {
        return (string) core()->getConfigData('general.telephony.zadarma.api_key');
    }

    /**
     * Return the API secret.
     */
    protected function secret(): string
    {
        return (string) core()->getConfigData('general.telephony.zadarma.api_secret');
    }

    /**
     * Determine whether a URL can be used publicly by Zadarma.
     */
    protected function isPublicUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return false;
        }

        $host = strtolower($host);

        return ! in_array($host, ['localhost', '127.0.0.1', '::1'])
            && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.local');
    }
}
