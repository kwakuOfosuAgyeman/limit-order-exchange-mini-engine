<script setup>
import { computed, onMounted, onUnmounted } from 'vue'
import { useOrdersStore } from '@/stores/orders'

const ordersStore = useOrdersStore()

let refreshInterval = null

const bids = computed(() => ordersStore.orderBook.bids || [])
const asks = computed(() => ordersStore.orderBook.asks || [])

function formatPrice(price) {
  return parseFloat(price).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

function formatAmount(amount) {
  return parseFloat(amount).toFixed(8)
}

onMounted(() => {
  // Refresh orderbook every 5 seconds
  refreshInterval = setInterval(() => {
    ordersStore.fetchOrderBook(ordersStore.selectedSymbol)
  }, 5000)
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})
</script>

<template>
  <div class="bg-gray-800 rounded-lg p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-white">Order Book</h2>
      <span class="text-sm text-gray-400">{{ ordersStore.selectedSymbol }}/USD</span>
    </div>

    <!-- Header -->
    <div class="grid grid-cols-3 gap-2 text-xs text-gray-500 mb-2 px-2">
      <span>Price (USD)</span>
      <span class="text-right">Amount</span>
      <span class="text-right">Total</span>
    </div>

    <!-- Sell Orders (Asks) - Red -->
    <div class="space-y-1 mb-4">
      <div
        v-for="(ask, index) in asks.slice(0, 10)"
        :key="'ask-' + index"
        class="grid grid-cols-3 gap-2 text-sm px-2 py-1 rounded hover:bg-gray-700/50"
      >
        <span class="text-red-400">${{ formatPrice(ask.price) }}</span>
        <span class="text-right text-gray-300">{{ formatAmount(ask.amount) }}</span>
        <span class="text-right text-gray-500">${{ formatPrice(ask.total) }}</span>
      </div>
      <div v-if="asks.length === 0" class="text-center text-gray-500 py-4 text-sm">
        No sell orders
      </div>
    </div>

    <!-- Spread Indicator -->
    <div class="border-y border-gray-700 py-2 mb-4">
      <div class="text-center text-gray-400 text-sm">
        Spread:
        <span v-if="bids.length > 0 && asks.length > 0" class="text-white">
          ${{ formatPrice(parseFloat(asks[0]?.price || 0) - parseFloat(bids[0]?.price || 0)) }}
        </span>
        <span v-else class="text-gray-500">--</span>
      </div>
    </div>

    <!-- Buy Orders (Bids) - Green -->
    <div class="space-y-1">
      <div
        v-for="(bid, index) in bids.slice(0, 10)"
        :key="'bid-' + index"
        class="grid grid-cols-3 gap-2 text-sm px-2 py-1 rounded hover:bg-gray-700/50"
      >
        <span class="text-green-400">${{ formatPrice(bid.price) }}</span>
        <span class="text-right text-gray-300">{{ formatAmount(bid.amount) }}</span>
        <span class="text-right text-gray-500">${{ formatPrice(bid.total) }}</span>
      </div>
      <div v-if="bids.length === 0" class="text-center text-gray-500 py-4 text-sm">
        No buy orders
      </div>
    </div>
  </div>
</template>
