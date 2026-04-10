<?php

namespace Webkul\Admin\Http\Controllers\Integration;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Throwable;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Resources\ActivityResource;
use Webkul\Admin\Services\ZadarmaService;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Repositories\LeadRepository;

class ZadarmaCallController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected ActivityRepository $activityRepository,
        protected LeadRepository $leadRepository,
        protected PersonRepository $personRepository,
        protected ZadarmaService $zadarmaService,
    ) {}

    /**
     * Register a Zadarma call attempt for an entity.
     */
    public function store(): JsonResponse
    {
        $validatedData = request()->validate([
            'entity_type' => 'required|in:leads,persons',
            'entity_id' => 'required|integer',
            'phone' => 'required|string|max:50',
            'contact_name' => 'nullable|string|max:255',
            'participant_person_id' => 'nullable|integer',
        ]);

        if (! $this->zadarmaService->isConfigured()) {
            return response()->json([
                'message' => trans('admin::app.integrations.zadarma.call.not-configured'),
            ], 422);
        }

        $webhookSyncWarning = null;

        try {
            $this->zadarmaService->ensureCallWebhookConfigured();
        } catch (Throwable $exception) {
            report($exception);

            $webhookSyncWarning = trans('admin::app.integrations.zadarma.call.webhook-sync-warning');
        }

        try {
            $callbackResponse = $this->zadarmaService->requestCallback($validatedData['phone']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage() ?: trans('admin::app.integrations.zadarma.call.failed'),
            ], 422);
        }

        Event::dispatch('activity.create.before');

        $activity = $this->activityRepository->create([
            'title' => trans('admin::app.integrations.zadarma.call.activity-title', [
                'name' => $validatedData['contact_name'] ?: $validatedData['phone'],
            ]),
            'type' => 'call',
            'comment' => trans('admin::app.integrations.zadarma.call.activity-comment', [
                'phone' => $validatedData['phone'],
            ]),
            'additional' => json_encode([
                'provider'         => 'zadarma',
                'status'           => 'requested',
                'status_label'     => trans('admin::app.integrations.zadarma.call.status-requested'),
                'phone'            => $this->zadarmaService->normalizePhone($validatedData['phone']),
                'source'           => $this->zadarmaService->source(),
                'sip'              => $this->zadarmaService->sip(),
                'request_time'     => $callbackResponse['time'] ?? null,
                'callback_response' => $callbackResponse,
            ]),
            'is_done' => 1,
            'user_id' => auth()->guard('user')->user()->id,
            'participants' => [
                'persons' => array_values(array_filter([
                    $validatedData['participant_person_id'] ?? null,
                ])),
            ],
        ]);

        if ($validatedData['entity_type'] === 'leads') {
            $lead = $this->leadRepository->findOrFail($validatedData['entity_id']);

            $lead->activities()->syncWithoutDetaching([$activity->id]);
        } else {
            $person = $this->personRepository->findOrFail($validatedData['entity_id']);

            $person->activities()->syncWithoutDetaching([$activity->id]);
        }

        Event::dispatch('activity.create.after', $activity);

        return response()->json([
            'data' => new ActivityResource($activity),
            'message' => trim(trans('admin::app.integrations.zadarma.call.success') . ' ' . ($webhookSyncWarning ?? '')),
        ]);
    }
}
