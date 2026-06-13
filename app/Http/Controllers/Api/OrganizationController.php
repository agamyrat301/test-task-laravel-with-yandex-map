<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\YandexMapsService;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function __construct(private YandexMapsService $yandex) {}

    /**
     * Текущая организация аутентифицированного пользователя.
     */
    public function show(Request $request)
    {
        $org = $request->user()->organizations()->latest()->first();

        return response()->json(['organization' => $org]);
    }

    /**
     * Сохранить URL организации и запустить синхронизацию.
     */
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
            ['yandex_url' => $request->url]
        );

        $this->yandex->sync($org);

        $org->refresh();

        return response()->json(['organization' => $org], 201);
    }

    /**
     * Принудительная повторная синхронизация.
     */
    public function sync(Request $request, Organization $organization)
    {
        $this->authorize('update', $organization);

        $this->yandex->sync($organization);
        $organization->refresh();

        return response()->json(['organization' => $organization]);
    }
}
