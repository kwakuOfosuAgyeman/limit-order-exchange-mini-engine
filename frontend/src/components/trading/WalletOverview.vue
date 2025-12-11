<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

const totalBalance = computed(() => {
  const available = parseFloat(authStore.balance) || 0
  const locked = parseFloat(authStore.lockedBalance) || 0
  return (available + locked).toFixed(2)
})

function formatNumber(value, decimals = 8) {
  const num = parseFloat(value) || 0
  return num.toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  })
}
</script>

<template>
  <div class="bg-gray-800 rounded-lg p-6">
    <h2 class="text-lg font-semibold text-white mb-4">Wallet</h2>

    <!-- USD Balance -->
    <div class="space-y-3 mb-6">
      <div class="flex justify-between items-center">
        <span class="text-gray-400">USD Balance</span>
        <span class="text-white font-medium">${{ formatNumber(authStore.balance, 2) }}</span>
      </div>
      <div class="flex justify-between items-center">
        <span class="text-gray-400">Locked (Orders)</span>
        <span class="text-yellow-400 font-medium">${{ formatNumber(authStore.lockedBalance, 2) }}</span>
      </div>
      <div class="flex justify-between items-center pt-2 border-t border-gray-700">
        <span class="text-gray-300 font-medium">Total</span>
        <span class="text-white font-bold">${{ formatNumber(totalBalance, 2) }}</span>
      </div>
    </div>

    <!-- Asset Balances -->
    <h3 class="text-sm font-medium text-gray-400 mb-3">Assets</h3>
    <div class="space-y-2">
      <div
        v-for="asset in authStore.assets"
        :key="asset.symbol"
        class="flex justify-between items-center bg-gray-700/50 rounded px-3 py-2"
      >
        <span class="text-white font-medium">{{ asset.symbol }}</span>
        <div class="text-right">
          <div class="text-white">{{ formatNumber(asset.amount) }}</div>
          <div v-if="parseFloat(asset.locked_amount) > 0" class="text-xs text-yellow-400">
            Locked: {{ formatNumber(asset.locked_amount) }}
          </div>
        </div>
      </div>
      <div v-if="authStore.assets?.length === 0" class="text-gray-500 text-sm">
        No assets yet
      </div>
    </div>
  </div>
</template>
