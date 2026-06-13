import axios from 'axios'

const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json' },
})

export async function getCsrfCookie() {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

export const auth = {
    login: (data) => api.post('/login', data),
    logout: () => api.post('/logout'),
    user: () => api.get('/user'),
}

export const organization = {
    get: () => api.get('/organization'),
    save: (url) => api.post('/organization', { url }),
    sync: (id) => api.post(`/organization/${id}/sync`),
    reviews: (id, page = 1) => api.get(`/organization/${id}/reviews`, { params: { page } }),
}

export default api
