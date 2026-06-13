<template>
    <div class="max-w-2xl mx-auto px-4 py-10">
        <h1 class="text-xl font-semibold text-gray-900 mb-6">Настройки</h1>

        <!-- Форма -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Ссылка на карточку организации в Яндекс.Картах
            </label>
            <div class="flex gap-2">
                <input
                    v-model="url"
                    type="url"
                    placeholder="https://yandex.ru/maps/org/…"
                    class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    :class="{ 'border-red-400': urlError }"
                    :disabled="saving || isSyncing"
                />
                <button
                    @click="save"
                    :disabled="saving || !url || isSyncing"
                    class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg transition whitespace-nowrap"
                >
                    {{ saving ? 'Сохраняем…' : 'Сохранить' }}
                </button>
            </div>
            <p v-if="urlError" class="text-red-500 text-xs mt-1">{{ urlError }}</p>
        </div>

        <!-- Статус синхронизации -->
        <div v-if="org && isSyncing" class="bg-blue-50 border border-blue-200 rounded-xl p-5 mb-6 flex items-center gap-3">
            <svg class="animate-spin h-5 w-5 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
            </svg>
            <div>
                <p class="text-sm font-medium text-blue-800">
                    {{ org.sync_status === 'pending' ? 'Запрос поставлен в очередь…' : 'Загружаем отзывы с Яндекс.Карт…' }}
                </p>
                <p class="text-xs text-blue-600 mt-0.5">До ~600 отзывов — займёт несколько минут</p>
            </div>
        </div>

        <!-- Ошибка синхронизации -->
        <div v-if="org && org.sync_status === 'failed'" class="bg-red-50 border border-red-200 rounded-xl p-5 mb-6">
            <p class="text-sm font-medium text-red-800">Не удалось загрузить данные</p>
            <p v-if="org.sync_error" class="text-xs text-red-600 mt-1 font-mono">{{ org.sync_error }}</p>
            <button @click="reSync" class="mt-3 text-xs text-red-700 underline">Попробовать снова</button>
        </div>

        <!-- Карточка организации (показываем только когда done) -->
        <div v-if="org && org.sync_status === 'done'" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ org.name }}</h2>
                    <p v-if="org.address" class="text-sm text-gray-500 mt-0.5">{{ org.address }}</p>
                </div>
                <button
                    @click="reSync"
                    :disabled="isSyncing"
                    class="text-xs text-blue-600 hover:text-blue-800 disabled:opacity-50"
                >Обновить данные</button>
            </div>

            <!-- Рейтинг -->
            <div class="flex items-center gap-3 mb-4">
                <span class="text-3xl font-bold text-gray-900">{{ org.rating?.toFixed(1) ?? '—' }}</span>
                <StarRating :value="org.rating ?? 0" />
            </div>

            <!-- Счётчики — раздельно оценки vs отзывы -->
            <div class="flex gap-6 text-sm text-gray-600 mb-4">
                <div>
                    <span class="font-semibold text-gray-900">{{ org.ratings_count.toLocaleString('ru') }}</span>
                    <span class="ml-1">оценок</span>
                </div>
                <div>
                    <span class="font-semibold text-gray-900">{{ org.reviews_count.toLocaleString('ru') }}</span>
                    <span class="ml-1">отзывов с текстом</span>
                </div>
            </div>

            <div class="text-xs text-gray-400 mb-4">
                Обновлено: {{ org.last_synced_at ? formatDate(org.last_synced_at) : '—' }}
            </div>

            <RouterLink
                to="/reviews"
                class="inline-block text-sm text-blue-600 hover:text-blue-800"
            >Перейти к отзывам →</RouterLink>
        </div>

        <p v-else-if="!org && !initialLoading && !saving" class="text-sm text-gray-500">
            Добавьте ссылку на организацию, чтобы подключить отзывы.
        </p>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { organization as orgApi } from '@/api'
import StarRating from '@/components/StarRating.vue'

const POLL_INTERVAL = 3000 // мс

const url            = ref('')
const urlError       = ref('')
const org            = ref(null)
const saving         = ref(false)
const initialLoading = ref(true)

let pollTimer = null

// Синхронизация идёт пока статус pending или syncing
const isSyncing = computed(() =>
    org.value?.sync_status === 'pending' || org.value?.sync_status === 'syncing'
)

onMounted(async () => {
    try {
        const { data } = await orgApi.get()
        org.value = data.organization
        if (org.value) {
            url.value = org.value.yandex_url
            if (isSyncing.value) startPolling()
        }
    } finally {
        initialLoading.value = false
    }
})

onUnmounted(() => stopPolling())

// -----------------------------------------------------------------------

async function save() {
    urlError.value = ''
    saving.value   = true
    try {
        const { data } = await orgApi.save(url.value)
        org.value = data.organization
        if (isSyncing.value) startPolling()
    } catch (err) {
        if (err.response?.status === 422) {
            urlError.value = err.response.data.errors?.url?.[0] ?? 'Ошибка валидации'
        } else {
            urlError.value = err.response?.data?.message ?? 'Не удалось сохранить'
        }
    } finally {
        saving.value = false
    }
}

async function reSync() {
    if (!org.value) return
    const { data } = await orgApi.sync(org.value.id)
    org.value = data.organization
    if (isSyncing.value) startPolling()
}

// -----------------------------------------------------------------------

function startPolling() {
    stopPolling()
    pollTimer = setInterval(async () => {
        try {
            const { data } = await orgApi.get()
            org.value = data.organization
            if (!isSyncing.value) stopPolling()
        } catch {
            stopPolling()
        }
    }, POLL_INTERVAL)
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer)
        pollTimer = null
    }
}

function formatDate(iso) {
    return new Date(iso).toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}
</script>
