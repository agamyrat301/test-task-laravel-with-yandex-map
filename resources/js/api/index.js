import axios from 'axios'
import router from '@/router'

const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json' },
})

// Сессия истекла — редиректим на логин
api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            router.push({ name: 'login' })
        }
        return Promise.reject(error)
    }
)

export async function getCsrfCookie() {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

export const auth = {
    login: (data) => api.post('/login', data),
    logout: () => api.post('/logout'),
    user:   () => api.get('/user'),
}

export const organization = {
    get:     ()         => api.get('/organization'),
    save:    (url)      => api.post('/organization', { url }),
    sync:    (id)       => api.post(`/organization/${id}/sync`),
    reviews: (id, page = 1) => api.get(`/organization/${id}/reviews`, { params: { page } }),
}

export default api
