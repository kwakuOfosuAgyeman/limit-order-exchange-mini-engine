<script setup>
import { ref, computed } from 'vue'
import { useOrdersStore } from '@/stores/orders'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

const ordersStore = useOrdersStore()
const authStore = useAuthStore()
const toast = useToast()

const activeTab = ref('open')
const cancellingId = ref(null)
const filterSymbol = ref('')
const filterSide = ref('')

const filteredByStatus = computed(() => {
  switch (activeTab.value) {
    case 'open':
      return ordersStore.openOrders
    case 'filled':
      return ordersStore.filledOrders
    case 'cancelled':
      return ordersStore.cancelledOrders
    default:
      return ordersStore.orders
  }
})

const displayedOrders = computed(() => {
  let orders = filteredByStatus.value

  if (filterSymbol.value) {
    orders = orders.filter((o) => o.symbol === filterSymbol.value)
  }

  if (filterSide.value) {
    orders = orders.filter((o) => o.side === filterSide.value)
  }

  return orders
})

const availableSymbols = computed(() => {
  return ordersStore.symbols.map((s) => s.symbol)
})

async function cancelOrder(orderId) {
  cancellingId.value = orderId
  try {
    const result = await ordersStore.cancelOrder(orderId)
    await authStore.fetchProfile()
    toast.success('Order cancelled successfully')
  } catch (err) {
    const message = err.response?.data?.message || 'Failed to cancel order'
    toast.error(message)
    console.error('Failed to cancel order:', err)
  } finally {
    cancellingId.value = null
  }
}

function formatDate(dateString) {
  if (!dateString) return '--'
  return new Date(dateString).toLocaleString()
}

function formatPrice(price) {
  return parseFloat(price).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

function formatAmount(amount) {
  return parseFloat(amount).toFixed(8)
}

function getStatusClass(status) {
  switch (status) {
    case 'open':
    case 'partially_filled':
      return 'bg-blue-500/20 text-blue-400'
    case 'filled':
      return 'bg-green-500/20 text-green-400'
    case 'cancelled':
    case 'expired':
      return 'bg-gray-500/20 text-gray-400'
    default:
      return 'bg-gray-500/20 text-gray-400'
  }
}
</script>

<template>
  <div class="bg-gray-800 rounded-lg p-6">
    <h2 class="text-lg font-semibold text-white mb-4">My Orders</h2>

    <!-- Tabs -->
    <div class="flex space-x-1 mb-4 bg-gray-700/50 rounded-lg p-1">
      <button
        @click="activeTab = 'open'"
        :class="[
          'flex-1 py-2 text-sm font-medium rounded-md transition-colors',
          activeTab === 'open'
            ? 'bg-gray-600 text-white'
            : 'text-gray-400 hover:text-white'
        ]"
      >
        Open
      </button>
      <button
        @click="activeTab = 'filled'"
        :class="[
          'flex-1 py-2 text-sm font-medium rounded-md transition-colors',
          activeTab === 'filled'
            ? 'bg-gray-600 text-white'
            : 'text-gray-400 hover:text-white'
        ]"
      >
        Filled
      </button>
      <button
        @click="activeTab = 'cancelled'"
        :class="[
          'flex-1 py-2 text-sm font-medium rounded-md transition-colors',
          activeTab === 'cancelled'
            ? 'bg-gray-600 text-white'
            : 'text-gray-400 hover:text-white'
        ]"
      >
        Cancelled
      </button>
    </div>

    <!-- Filters -->
    <div class="flex gap-3 mb-4">
      <select
        v-model="filterSymbol"
        class="flex-1 bg-gray-700 border border-gray-600 text-white text-sm rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
      >
        <option value="">All Symbols</option>
        <option v-for="symbol in availableSymbols" :key="symbol" :value="symbol">
          {{ symbol }}
        </option>
      </select>
      <select
        v-model="filterSide"
        class="flex-1 bg-gray-700 border border-gray-600 text-white text-sm rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
      >
        <option value="">All Sides</option>
        <option value="buy">Buy</option>
        <option value="sell">Sell</option>
      </select>
    </div>

    <!-- Orders List -->
    <div class="space-y-3 max-h-96 overflow-y-auto">
      <div
        v-for="order in displayedOrders"
        :key="order.id"
        class="bg-gray-700/50 rounded-lg p-4"
      >
        <div class="flex items-center justify-between mb-2">
          <div class="flex items-center space-x-2">
            <span
              :class="[
                'px-2 py-0.5 text-xs font-medium rounded',
                order.side === 'buy' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'
              ]"
            >
              {{ order.side.toUpperCase() }}
            </span>
            <span class="text-white font-medium">{{ order.symbol }}</span>
          </div>
          <span
            :class="['px-2 py-0.5 text-xs font-medium rounded', getStatusClass(order.status)]"
          >
            {{ order.status_label }}
          </span>
        </div>

        <div class="grid grid-cols-2 gap-2 text-sm mb-2">
          <div>
            <span class="text-gray-500">Price:</span>
            <span class="text-white ml-1">${{ formatPrice(order.price) }}</span>
          </div>
          <div>
            <span class="text-gray-500">Amount:</span>
            <span class="text-white ml-1">{{ formatAmount(order.amount) }}</span>
          </div>
          <div>
            <span class="text-gray-500">Filled:</span>
            <span class="text-white ml-1">{{ formatAmount(order.filled_amount) }}</span>
          </div>
          <div>
            <span class="text-gray-500">Total:</span>
            <span class="text-white ml-1">${{ formatPrice(order.total_value) }}</span>
          </div>
        </div>

        <div class="flex items-center justify-between text-xs">
          <span class="text-gray-500">{{ formatDate(order.created_at) }}</span>
          <button
            v-if="order.can_be_cancelled"
            @click="cancelOrder(order.id)"
            :disabled="cancellingId === order.id"
            class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-xs font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ cancellingId === order.id ? 'Cancelling...' : 'Cancel' }}
          </button>
        </div>
      </div>

      <div v-if="displayedOrders.length === 0" class="text-center text-gray-500 py-8">
        <span v-if="filterSymbol || filterSide">No orders match the selected filters</span>
        <span v-else>No orders found</span>
      </div>
    </div>
  </div>
</template>
