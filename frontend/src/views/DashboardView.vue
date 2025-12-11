<script setup>
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useOrdersStore } from '@/stores/orders'
import { useEcho } from '@/composables/useEcho'
import AppHeader from '@/components/layout/AppHeader.vue'
import WalletOverview from '@/components/trading/WalletOverview.vue'
import LimitOrderForm from '@/components/trading/LimitOrderForm.vue'
import OrderBook from '@/components/trading/OrderBook.vue'
import OrderHistory from '@/components/trading/OrderHistory.vue'

const authStore = useAuthStore()
const ordersStore = useOrdersStore()
const { initEcho, subscribeToUserChannel } = useEcho()

onMounted(async () => {
  // Fetch initial data
  await Promise.all([
    authStore.fetchProfile(),
    ordersStore.fetchSymbols(),
    ordersStore.fetchOrders(),
    ordersStore.fetchOrderBook(ordersStore.selectedSymbol),
  ])

  // Initialize real-time connection
  if (authStore.user?.id) {
    initEcho()
    subscribeToUserChannel(authStore.user.id)
  }
})
</script>

<template>
  <div class="min-h-screen bg-gray-900">
    <AppHeader />

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column: Wallet & Order Form -->
        <div class="space-y-6">
          <WalletOverview />
          <LimitOrderForm />
        </div>

        <!-- Center Column: Order Book -->
        <div>
          <OrderBook />
        </div>

        <!-- Right Column: Order History -->
        <div>
          <OrderHistory />
        </div>
      </div>
    </main>
  </div>
</template>
