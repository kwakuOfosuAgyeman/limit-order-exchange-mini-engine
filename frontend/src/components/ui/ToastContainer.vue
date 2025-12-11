<script setup>
import { useToast } from '@/composables/useToast'

const { toasts, removeToast } = useToast()

const typeStyles = {
  success: 'bg-green-600 border-green-500',
  error: 'bg-red-600 border-red-500',
  warning: 'bg-yellow-600 border-yellow-500',
  info: 'bg-blue-600 border-blue-500',
}

const typeIcons = {
  success: '✓',
  error: '✕',
  warning: '⚠',
  info: 'ℹ',
}
</script>

<template>
  <div class="fixed top-4 right-4 z-50 flex flex-col gap-2 max-w-sm">
    <TransitionGroup name="toast">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        :class="[
          'flex items-start gap-3 p-4 rounded-lg border shadow-lg text-white',
          typeStyles[toast.type] || typeStyles.info,
        ]"
      >
        <span class="text-lg font-bold">{{ typeIcons[toast.type] || typeIcons.info }}</span>
        <p class="flex-1 text-sm">{{ toast.message }}</p>
        <button
          @click="removeToast(toast.id)"
          class="text-white/80 hover:text-white transition-colors"
        >
          ✕
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
.toast-enter-active {
  transition: all 0.3s ease-out;
}

.toast-leave-active {
  transition: all 0.2s ease-in;
}

.toast-enter-from {
  opacity: 0;
  transform: translateX(100%);
}

.toast-leave-to {
  opacity: 0;
  transform: translateX(100%);
}
</style>
