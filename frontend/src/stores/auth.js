import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi } from '@/api/auth'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(JSON.parse(localStorage.getItem('user')) || null)
  const token = ref(localStorage.getItem('token') || null)
  const loading = ref(false)
  const error = ref(null)

  const isAuthenticated = computed(() => !!token.value)
  const balance = computed(() => user.value?.balance || '0')
  const lockedBalance = computed(() => user.value?.locked_balance || '0')
  const assets = computed(() => user.value?.assets || [])

  async function login(email, password) {
    loading.value = true
    error.value = null
    try {
      const data = await authApi.login(email, password)
      setAuth(data.user, data.token)
      return data
    } catch (err) {
      error.value = err.response?.data?.message || 'Login failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function register(name, email, password, password_confirmation) {
    loading.value = true
    error.value = null
    try {
      const data = await authApi.register(name, email, password, password_confirmation)
      setAuth(data.user, data.token)
      return data
    } catch (err) {
      error.value = err.response?.data?.message || 'Registration failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    try {
      await authApi.logout()
    } catch (err) {
      // Ignore logout errors
    } finally {
      clearAuth()
    }
  }

  async function fetchProfile() {
    if (!token.value) return
    try {
      const data = await authApi.getProfile()
      user.value = data.user
      localStorage.setItem('user', JSON.stringify(data.user))
    } catch (err) {
      console.error('Failed to fetch profile:', err)
    }
  }

  function setAuth(userData, tokenValue) {
    user.value = userData
    token.value = tokenValue
    localStorage.setItem('user', JSON.stringify(userData))
    localStorage.setItem('token', tokenValue)
  }

  function clearAuth() {
    user.value = null
    token.value = null
    localStorage.removeItem('user')
    localStorage.removeItem('token')
  }

  function updateBalance(newBalance, newLockedBalance) {
    if (user.value) {
      user.value.balance = newBalance
      user.value.locked_balance = newLockedBalance
      localStorage.setItem('user', JSON.stringify(user.value))
    }
  }

  function updateAssets(newAssets) {
    if (user.value) {
      user.value.assets = newAssets
      localStorage.setItem('user', JSON.stringify(user.value))
    }
  }

  return {
    user,
    token,
    loading,
    error,
    isAuthenticated,
    balance,
    lockedBalance,
    assets,
    login,
    register,
    logout,
    fetchProfile,
    setAuth,
    clearAuth,
    updateBalance,
    updateAssets,
  }
})
