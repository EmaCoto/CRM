<?php

namespace Webkul\Admin\Http\Controllers\Integration;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Services\ZadarmaService;

class ZadarmaWebhookController extends Controller
{
    /**
     * Handle Zadarma call event webhooks.
     */
    public function handle(Request $request, ZadarmaService $zadarmaService): Response
    {
        if ($request->filled('zd_echo')) {
            return response((string) $request->string('zd_echo'));
        }

        $payload = $request->all();

        if (! $zadarmaService->isValidWebhookSignature($payload, $request->header('Signature'))) {
            return response('Invalid signature.', 403);
        }

        $activity = $this->findActivityForEvent($payload, $zadarmaService);

        if (! $activity) {
            return response('OK');
        }

        $additional = is_array($activity->additional)
            ? $activity->additional
            : (json_decode($activity->additional ?? '{}', true) ?: []);

        $additional = array_merge($additional, [
            'provider'         => 'zadarma',
            'status'           => $this->resolveStatus($payload),
            'status_label'     => $this->resolveStatusLabel($this->resolveStatus($payload)),
            'pbx_call_id'      => $payload['pbx_call_id'] ?? ($additional['pbx_call_id'] ?? null),
            'call_start'       => $payload['call_start'] ?? ($additional['call_start'] ?? null),
            'destination'      => $zadarmaService->normalizePhone($payload['destination'] ?? ($additional['destination'] ?? $additional['phone'] ?? '')),
            'caller_id'        => $zadarmaService->normalizePhone($payload['caller_id'] ?? ($additional['caller_id'] ?? '')),
            'internal'         => $payload['internal'] ?? ($additional['internal'] ?? null),
            'duration'         => isset($payload['duration']) ? (int) $payload['duration'] : ($additional['duration'] ?? null),
            'disposition'      => $payload['disposition'] ?? ($additional['disposition'] ?? null),
            'is_recorded'      => $payload['is_recorded'] ?? ($additional['is_recorded'] ?? null),
            'call_id_with_rec' => $payload['call_id_with_rec'] ?? ($additional['call_id_with_rec'] ?? null),
        ]);

        $updateData = [
            'additional' => json_encode($additional),
            'comment'    => $this->buildComment($activity->comment, $additional),
        ];

        if (! empty($additional['call_start'])) {
            $scheduleFrom = Carbon::createFromTimestamp((int) $additional['call_start']);

            $updateData['schedule_from'] = $scheduleFrom;

            if (! empty($additional['duration'])) {
                $updateData['schedule_to'] = $scheduleFrom->copy()->addSeconds((int) $additional['duration']);
            }
        }

        $activity->update($updateData);

        return response('OK');
    }

    /**
     * Find the best matching activity for a Zadarma webhook.
     */
    protected function findActivityForEvent(array $payload, ZadarmaService $zadarmaService)
    {
        $query = ActivityProxy::query()
            ->where('type', 'call')
            ->where('additional->provider', 'zadarma');

        if (! empty($payload['pbx_call_id'])) {
            $activity = (clone $query)
                ->where('additional->pbx_call_id', $payload['pbx_call_id'])
                ->latest('id')
                ->first();

            if ($activity) {
                return $activity;
            }
        }

        $destination = $zadarmaService->normalizePhone($payload['destination'] ?? '');

        if ($destination === '') {
            return null;
        }

        return $query
            ->where('additional->phone', $destination)
            ->latest('id')
            ->first();
    }

    /**
     * Resolve normalized call status.
     */
    protected function resolveStatus(array $payload): string
    {
        return match ($payload['event'] ?? null) {
            'NOTIFY_ANSWER' => 'answered',
            'NOTIFY_OUT_END' => 'finished',
            default => 'requested',
        };
    }

    /**
     * Resolve translated status label.
     */
    protected function resolveStatusLabel(string $status): string
    {
        return match ($status) {
            'answered' => trans('admin::app.integrations.zadarma.call.status-answered'),
            'finished' => trans('admin::app.integrations.zadarma.call.status-finished'),
            default => trans('admin::app.integrations.zadarma.call.status-requested'),
        };
    }

    /**
     * Build a human-readable activity comment.
     */
    protected function buildComment(?string $existingComment, array $additional): string
    {
        $parts = array_filter([
            $existingComment,
            ! empty($additional['disposition']) ? 'Status: ' . $additional['disposition'] : null,
            ! empty($additional['duration']) ? 'Duration: ' . $additional['duration'] . ' sec' : null,
        ]);

        return implode('<br>', array_unique($parts));
    }
}
