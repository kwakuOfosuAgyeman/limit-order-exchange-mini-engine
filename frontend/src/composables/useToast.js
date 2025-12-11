import { ref } from 'vue'

const toasts = ref([])
let toastId = 0

export function useToast() {
  function addToast(message, type = 'info', duration = 5000) {
    const id = ++toastId
    toasts.value.push({ id, message, type })

    // Auto-remove after duration
    if (duration > 0) {
      setTimeout(() => {
        removeToast(id)
      }, duration)
    }

    return id
  }

  function removeToast(id) {
    const index = toasts.value.findIndex((t) => t.id === id)
    if (index !== -1) {
      toasts.value.splice(index, 1)
    }
  }

  function success(message, duration = 5000) {
    return addToast(message, 'success', duration)
  }

  function error(message, duration = 5000) {
    return addToast(message, 'error', duration)
  }

  function info(message, duration = 5000) {
    return addToast(message, 'info', duration)
  }

  function warning(message, duration = 5000) {
    return addToast(message, 'warning', duration)
  }

  return {
    toasts,
    addToast,
    removeToast,
    success,
    error,
    info,
    warning,
  }
}
