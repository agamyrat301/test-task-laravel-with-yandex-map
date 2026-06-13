<template>
    <div class="min-h-screen bg-gray-50">
        <nav v-if="auth.isAuthenticated()" class="bg-white border-b border-gray-200">
            <div class="max-w-4xl mx-auto px-4 py-3 flex items-center justify-between">
                <div class="flex gap-6 text-sm font-medium">
                    <RouterLink
                        to="/"
                        class="text-gray-600 hover:text-gray-900"
                        active-class="text-blue-600"
                    >Настройки</RouterLink>
                    <RouterLink
                        to="/reviews"
                        class="text-gray-600 hover:text-gray-900"
                        active-class="text-blue-600"
                    >Отзывы</RouterLink>
                </div>
                <button
                    @click="handleLogout"
                    class="text-sm text-gray-500 hover:text-gray-700"
                >Выйти</button>
            </div>
        </nav>

        <RouterView />
    </div>
</template>

<script setup>
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'

const auth = useAuthStore()
const router = useRouter()

async function handleLogout() {
    await auth.logout()
    router.push({ name: 'login' })
}
</script>
