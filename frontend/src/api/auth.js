import api from './index'

export const authApi = {
  async login(email, password) {
    const response = await api.post('/login', { email, password })
    return response.data
  },

  async register(name, email, password, password_confirmation) {
    const response = await api.post('/register', {
      name,
      email,
      password,
      password_confirmation,
    })
    return response.data
  },

  async logout() {
    const response = await api.post('/logout')
    return response.data
  },

  async getProfile() {
    const response = await api.get('/profile')
    return response.data
  },
}
