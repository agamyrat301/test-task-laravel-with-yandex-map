<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\YandexMapsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncOrganizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Яндекс иногда даёт временные сбои — до 3 попыток с экспоненциальной паузой
    public int $tries   = 3;
    public int $timeout = 600; // 10 мин — запас для ~600 отзывов с задержками

    public function __construct(private readonly Organization $org) {}

    public function handle(YandexMapsService $yandex): void
    {
        $this->org->update([
            'sync_status' => 'syncing',
            'sync_error'  => null,
        ]);

        $yandex->sync($this->org);

        $this->org->update(['sync_status' => 'done']);
    }

    public function failed(Throwable $e): void
    {
        $this->org->update([
            'sync_status' => 'failed',
            'sync_error'  => $e->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [30, 120, 300]; // 30с → 2м → 5м между попытками
    }
}
