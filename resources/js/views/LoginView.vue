<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div class="w-full max-w-sm bg-white rounded-xl shadow-sm border border-gray-200 p-8">
            <h1 class="text-xl font-semibold text-gray-900 mb-6 text-center">Вход</h1>

            <form @submit.prevent="submit" novalidate>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        :class="{ 'border-red-400': errors.email }"
                    />
                    <p v-if="errors.email" class="text-red-500 text-xs mt-1">{{ errors.email[0] }}</p>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Пароль</label>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        :class="{ 'border-red-400': errors.password }"
                    />
                    <p v-if="errors.password" class="text-red-500 text-xs mt-1">{{ errors.password[0] }}</p>
                </div>

                <button
                    type="submit"
                    :disabled="loading"
                    class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium py-2 rounded-lg transition"
                >
                    {{ loading ? 'Входим…' : 'Войти' }}
                </button>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router  = useRouter()
const auth    = useAuthStore()
const loading = ref(false)
const errors  = reactive({})

const form = reactive({
    email:    '',
    password: '',
})

async function submit() {
    Object.keys(errors).forEach(k => delete errors[k])
    loading.value = true

    try {
        await auth.login(form.email, form.password)
        router.push({ name: 'settings' })
    } catch (err) {
        if (err.response?.status === 422) {
            Object.assign(errors, err.response.data.errors ?? {})
        } else {
            errors.email = ['Что-то пошло не так. Попробуйте снова.']
        }
    } finally {
        loading.value = false
    }
}
</script>
