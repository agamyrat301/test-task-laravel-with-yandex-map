<template>
    <div class="max-w-3xl mx-auto px-4 py-10">

        <!-- Нет организации -->
        <div v-if="!org && !loading" class="text-center py-20 text-gray-500 text-sm">
            Сначала добавьте организацию в
            <RouterLink to="/" class="text-blue-600 hover:underline">настройках</RouterLink>.
        </div>

        <!-- Синхронизация ещё идёт -->
        <div v-else-if="isSyncing" class="text-center py-20">
            <svg class="animate-spin h-8 w-8 text-blue-400 mx-auto mb-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
            </svg>
            <p class="text-sm text-gray-600 font-medium">Загружаем отзывы с Яндекс.Карт…</p>
            <p class="text-xs text-gray-400 mt-1">Это займёт несколько минут</p>
        </div>

        <template v-else-if="org">
            <!-- Шапка: рейтинг + счётчики -->
            <div class="mb-8">
                <h1 class="text-xl font-semibold text-gray-900">{{ org.name }}</h1>
                <p v-if="org.address" class="text-sm text-gray-500 mt-0.5">{{ org.address }}</p>

                <div class="flex items-center gap-3 mt-3">
                    <span class="text-4xl font-bold text-gray-900">{{ org.rating?.toFixed(1) ?? '—' }}</span>
                    <div>
                        <StarRating :value="org.rating ?? 0" />
                        <div class="flex gap-5 mt-1 text-sm text-gray-500">
                            <span>
                                <strong class="text-gray-800">{{ org.ratings_count.toLocaleString('ru') }}</strong>
                                оценок
                            </span>
                            <span>
                                <strong class="text-gray-800">{{ org.reviews_count.toLocaleString('ru') }}</strong>
                                отзывов с текстом
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Загрузка страницы -->
            <div v-if="loading" class="text-center py-16 text-gray-400 text-sm">
                Загружаем…
            </div>

            <template v-else-if="reviews.length">
                <div class="space-y-4 mb-8">
                    <div
                        v-for="review in reviews"
                        :key="review.id"
                        class="bg-white rounded-xl border border-gray-200 shadow-sm p-5"
                    >
                        <div class="flex items-start justify-between mb-2">
                            <div>
                                <p class="font-medium text-gray-900 text-sm">{{ review.author }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ formatDate(review.reviewed_at) }}</p>
                            </div>
                            <StarRating :value="review.rating" />
                        </div>
                        <p v-if="review.text" class="text-sm text-gray-700 leading-relaxed">{{ review.text }}</p>
                        <p v-else class="text-xs text-gray-400 italic">Оценка без текста</p>
                    </div>
                </div>

                <!-- Пагинация -->
                <div class="flex items-center justify-between text-sm">
                    <button
                        @click="goToPage(pagination.current_page - 1)"
                        :disabled="pagination.current_page === 1"
                        class="px-4 py-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                    >← Назад</button>

                    <span class="text-gray-500">
                        Стр. {{ pagination.current_page }} / {{ pagination.last_page }}
                        <span class="text-gray-400 ml-2">{{ pagination.total.toLocaleString('ru') }} отзывов</span>
                    </span>

                    <button
                        @click="goToPage(pagination.current_page + 1)"
                        :disabled="pagination.current_page === pagination.last_page"
                        class="px-4 py-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                    >Вперёд →</button>
                </div>
            </template>

            <p v-else class="text-sm text-gray-500 py-10 text-center">Отзывов пока нет.</p>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { organization as orgApi } from '@/api'
import StarRating from '@/components/StarRating.vue'

const org     = ref(null)
const reviews = ref([])
const loading = ref(true)

const pagination = ref({ current_page: 1, last_page: 1, total: 0 })

const isSyncing = computed(() =>
    org.value?.sync_status === 'pending' || org.value?.sync_status === 'syncing'
)

onMounted(async () => {
    const { data } = await orgApi.get()
    org.value = data.organization

    if (org.value && !isSyncing.value) {
        await loadReviews(1)
    } else {
        loading.value = false
    }
})

async function loadReviews(page) {
    loading.value = true
    try {
        const { data } = await orgApi.reviews(org.value.id, page)
        reviews.value  = data.data
        pagination.value = {
            current_page: data.current_page,
            last_page:    data.last_page,
            total:        data.total,
        }
    } finally {
        loading.value = false
    }
}

function goToPage(page) {
    if (page < 1 || page > pagination.value.last_page) return
    loadReviews(page)
    window.scrollTo({ top: 0, behavior: 'smooth' })
}

function formatDate(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleDateString('ru-RU', {
        day: '2-digit', month: 'long', year: 'numeric',
    })
}
</script>
