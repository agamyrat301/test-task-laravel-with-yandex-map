<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncOrganizationJob;
use App\Models\Organization;
use App\Services\YandexMapsService;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function __construct(private YandexMapsService $yandex) {}

    public function show(Request $request)
    {
        $org = $request->user()->organizations()->latest()->first();

        return response()->json(['organization' => $org]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'url' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (!$this->yandex->validateUrl($value)) {
                        $fail('Введите корректную ссылку на карточку организации в Яндекс.Картах.');
                    }
                },
            ],
        ]);

        $orgId = $this->yandex->extractOrgId($request->url);

        $org = $request->user()->organizations()->updateOrCreate(
            ['yandex_org_id' => $orgId],
            [
                'yandex_url'  => $request->url,
                'sync_status' => 'pending',
                'sync_error'  => null,
            ]
        );

        // Отвечаем мгновенно — парсинг идёт в очереди (до 10 мин для ~600 отзывов)
        SyncOrganizationJob::dispatch($org);

        return response()->json(['organization' => $org->refresh()], 201);
    }

    public function sync(Request $request, Organization $organization)
    {
        $this->authorize('update', $organization);

        // Повторная синхронизация тоже через очередь
        $organization->update(['sync_status' => 'pending', 'sync_error' => null]);
        SyncOrganizationJob::dispatch($organization);

        return response()->json(['organization' => $organization->refresh()]);
    }
}
