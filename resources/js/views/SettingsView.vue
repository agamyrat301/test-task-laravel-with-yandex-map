<template>
    <div class="max-w-2xl mx-auto px-4 py-10">
        <h1 class="text-xl font-semibold text-gray-900 mb-6">Настройки</h1>

        <!-- Форма добавления организации -->
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
                    :disabled="saving"
                />
                <button
                    @click="save"
                    :disabled="saving || !url"
                    class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg transition whitespace-nowrap"
                >
                    {{ saving ? 'Сохраняем…' : 'Сохранить' }}
                </button>
            </div>
            <p v-if="urlError" class="text-red-500 text-xs mt-1">{{ urlError }}</p>
        </div>

        <!-- Карточка организации -->
        <div v-if="org" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ org.name }}</h2>
                    <p v-if="org.address" class="text-sm text-gray-500 mt-0.5">{{ org.address }}</p>
                </div>
                <button
                    @click="sync"
                    :disabled="syncing"
                    class="text-xs text-blue-600 hover:text-blue-800 disabled:opacity-50"
                >{{ syncing ? 'Обновляем…' : 'Обновить данные' }}</button>
            </div>

            <div class="flex items-center gap-3 mb-4">
                <span class="text-3xl font-bold text-gray-900">{{ org.rating?.toFixed(1) ?? '—' }}</span>
                <StarRating :value="org.rating ?? 0" />
            </div>

            <div class="flex gap-6 text-sm text-gray-600">
                <div>
                    <span class="font-semibold text-gray-900">{{ org.ratings_count }}</span>
                    <span class="ml-1">оценок</span>
                </div>
                <div>
                    <span class="font-semibold text-gray-900">{{ org.reviews_count }}</span>
                    <span class="ml-1">отзывов</span>
                </div>
            </div>

            <div class="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
                Последнее обновление:
                {{ org.last_synced_at ? formatDate(org.last_synced_at) : 'нет данных' }}
            </div>

            <RouterLink
                to="/reviews"
                class="mt-4 inline-block text-sm text-blue-600 hover:text-blue-800"
            >Перейти к отзывам →</RouterLink>
        </div>

        <p v-else-if="!saving && !initialLoading" class="text-sm text-gray-500">
            Добавьте ссылку на организацию, чтобы подключить отзывы.
        </p>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { organization as orgApi } from '@/api'
import StarRating from '@/components/StarRating.vue'

const url            = ref('')
const urlError       = ref('')
const org            = ref(null)
const saving         = ref(false)
const syncing        = ref(false)
const initialLoading = ref(true)

onMounted(async () => {
    try {
        const { data } = await orgApi.get()
        org.value = data.organization
        if (org.value) {
            url.value = org.value.yandex_url
        }
    } finally {
        initialLoading.value = false
    }
})

async function save() {
    urlError.value = ''
    saving.value   = true
    try {
        const { data } = await orgApi.save(url.value)
        org.value = data.organization
    } catch (err) {
        if (err.response?.status === 422) {
            urlError.value = err.response.data.errors?.url?.[0] ?? 'Ошибка валидации'
        } else {
            urlError.value = err.response?.data?.message ?? 'Не удалось сохранить. Попробуйте снова.'
        }
    } finally {
        saving.value = false
    }
}

async function sync() {
    syncing.value = true
    try {
        const { data } = await orgApi.sync(org.value.id)
        org.value = data.organization
    } finally {
        syncing.value = false
    }
}

function formatDate(iso) {
    return new Date(iso).toLocaleString('ru-RU', {
        day:    '2-digit',
        month:  '2-digit',
        year:   'numeric',
        hour:   '2-digit',
        minute: '2-digit',
    })
}
</script>
