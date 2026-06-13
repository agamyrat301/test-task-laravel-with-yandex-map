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

        // user_id явно в ключе поиска — без него updateOrCreate полагается только
        // на скоуп relationship; если код переедет в Job без контекста пользователя,
        // можно было бы случайно обновить запись другого юзера с тем же org_id.
        $org = $request->user()->organizations()->updateOrCreate(
            ['yandex_org_id' => $orgId, 'user_id' => $request->user()->id],
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
