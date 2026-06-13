import { defineStore } from 'pinia'
import { ref } from 'vue'
import { auth as authApi, getCsrfCookie } from '@/api'

export const useAuthStore = defineStore('auth', () => {
    const user = ref(null)
    const loading = ref(false)

    async function login(email, password) {
        await getCsrfCookie()
        await authApi.login({ email, password })
        const { data } = await authApi.user()
        user.value = data.user
    }

    async function logout() {
        await authApi.logout()
        user.value = null
    }

    async function fetchUser() {
        try {
            loading.value = true
            const { data } = await authApi.user()
            user.value = data.user
        } catch {
            user.value = null
        } finally {
            loading.value = false
        }
    }

    const isAuthenticated = () => !!user.value

    return { user, loading, login, logout, fetchUser, isAuthenticated }
})
