import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('@/views/LoginView.vue'),
        meta: { guest: true },
    },
    {
        path: '/',
        name: 'settings',
        component: () => import('@/views/SettingsView.vue'),
        meta: { auth: true },
    },
    {
        path: '/reviews',
        name: 'reviews',
        component: () => import('@/views/ReviewsView.vue'),
        meta: { auth: true },
    },
]

const router = createRouter({
    history: createWebHistory(),
    routes,
})

router.beforeEach(async (to) => {
    const store = useAuthStore()

    if (!store.user && !store.loading) {
        await store.fetchUser()
    }

    if (to.meta.auth && !store.isAuthenticated()) {
        return { name: 'login' }
    }

    if (to.meta.guest && store.isAuthenticated()) {
        return { name: 'settings' }
    }
})

export default router
