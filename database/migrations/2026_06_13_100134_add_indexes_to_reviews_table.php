<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            // Индекс для сортировки по дате (ORDER BY reviewed_at DESC)
            $table->index('reviewed_at');
            // yandex_review_id — фактически никогда не null (normalizeReview пропускает без id),
            // но изменить nullable->not null без пересоздания таблицы с данными сложнее,
            // поэтому ограничиваемся индексом, который уже есть через unique()
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['reviewed_at']);
        });
    }
};
