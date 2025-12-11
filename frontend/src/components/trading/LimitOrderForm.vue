<script setup>
import { ref, computed, watch } from 'vue'
import { useOrdersStore } from '@/stores/orders'
import { useAuthStore } from '@/stores/auth'

const ordersStore = useOrdersStore()
const authStore = useAuthStore()

const side = ref('buy')
const price = ref('')
const amount = ref('')
const error = ref('')
const success = ref('')
const loading = ref(false)

const selectedSymbol = computed(() => ordersStore.selectedSymbol)

const total = computed(() => {
  const p = parseFloat(price.value) || 0
  const a = parseFloat(amount.value) || 0
  return (p * a).toFixed(2)
})

const fee = computed(() => {
  const t = parseFloat(total.value) || 0
  return (t * 0.015).toFixed(2) // 1.5% fee
})

const grandTotal = computed(() => {
  const t = parseFloat(total.value) || 0
  const f = parseFloat(fee.value) || 0
  return side.value === 'buy' ? (t + f).toFixed(2) : t.toFixed(2)
})

watch(selectedSymbol, () => {
  ordersStore.fetchOrderBook(selectedSymbol.value)
})

async function handleSubmit() {
  error.value = ''
  success.value = ''
  loading.value = true

  try {
    const result = await ordersStore.createOrder(
      selectedSymbol.value,
      side.value,
      price.value,
      amount.value
    )
    success.value = `Order created successfully!`
    price.value = ''
    amount.value = ''

    // Refresh data
    await Promise.all([
      authStore.fetchProfile(),
      ordersStore.fetchOrderBook(selectedSymbol.value),
    ])
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to create order'
  } finally {
    loading.value = false
  }
}

function setSymbol(symbol) {
  ordersStore.setSelectedSymbol(symbol)
}
</script>

<template>
  <div class="bg-gray-800 rounded-lg p-6">
    <h2 class="text-lg font-semibold text-white mb-4">Place Order</h2>

    <!-- Symbol Selection -->
    <div class="mb-4">
      <label class="block text-sm font-medium text-gray-400 mb-2">Symbol</label>
      <div class="flex space-x-2">
        <button
          v-for="sym in ordersStore.symbols"
          :key="sym.symbol"
          @click="setSymbol(sym.symbol)"
          :class="[
            'px-4 py-2 rounded-md text-sm font-medium transition-colors',
            selectedSymbol === sym.symbol
              ? 'bg-blue-600 text-white'
              : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
          ]"
        >
          {{ sym.symbol }}
        </button>
      </div>
    </div>

    <!-- Side Selection -->
    <div class="mb-4">
      <label class="block text-sm font-medium text-gray-400 mb-2">Side</label>
      <div class="grid grid-cols-2 gap-2">
        <button
          @click="side = 'buy'"
          :class="[
            'py-2 rounded-md text-sm font-medium transition-colors',
            side === 'buy'
              ? 'bg-green-600 text-white'
              : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
          ]"
        >
          Buy
        </button>
        <button
          @click="side = 'sell'"
          :class="[
            'py-2 rounded-md text-sm font-medium transition-colors',
            side === 'sell'
              ? 'bg-red-600 text-white'
              : 'bg-gray-700 text-gray-300 hover:bg-gray-600'
          ]"
        >
          Sell
        </button>
      </div>
    </div>

    <form @submit.prevent="handleSubmit" class="space-y-4">
      <!-- Error/Success Messages -->
      <div v-if="error" class="bg-red-500/10 border border-red-500 text-red-400 px-3 py-2 rounded text-sm">
        {{ error }}
      </div>
      <div v-if="success" class="bg-green-500/10 border border-green-500 text-green-400 px-3 py-2 rounded text-sm">
        {{ success }}
      </div>

      <!-- Price Input -->
      <div>
        <label class="block text-sm font-medium text-gray-400 mb-1">Price (USD)</label>
        <input
          v-model="price"
          type="number"
          step="0.01"
          min="0"
          required
          class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500"
          placeholder="0.00"
        />
      </div>

      <!-- Amount Input -->
      <div>
        <label class="block text-sm font-medium text-gray-400 mb-1">Amount ({{ selectedSymbol }})</label>
        <input
          v-model="amount"
          type="number"
          step="0.00000001"
          min="0"
          required
          class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500"
          placeholder="0.00000000"
        />
      </div>

      <!-- Order Summary -->
      <div class="bg-gray-700/50 rounded-md p-3 space-y-2 text-sm">
        <div class="flex justify-between">
          <span class="text-gray-400">Total</span>
          <span class="text-white">${{ total }}</span>
        </div>
        <div v-if="side === 'buy'" class="flex justify-between">
          <span class="text-gray-400">Fee (1.5%)</span>
          <span class="text-yellow-400">${{ fee }}</span>
        </div>
        <div class="flex justify-between pt-2 border-t border-gray-600">
          <span class="text-gray-300 font-medium">{{ side === 'buy' ? 'You Pay' : 'You Receive' }}</span>
          <span class="text-white font-bold">${{ grandTotal }}</span>
        </div>
      </div>

      <!-- Submit Button -->
      <button
        type="submit"
        :disabled="loading"
        :class="[
          'w-full py-3 rounded-md text-white font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed',
          side === 'buy'
            ? 'bg-green-600 hover:bg-green-700'
            : 'bg-red-600 hover:bg-red-700'
        ]"
      >
        {{ loading ? 'Processing...' : (side === 'buy' ? 'Place Buy Order' : 'Place Sell Order') }}
      </button>
    </form>
  </div>
</template>
