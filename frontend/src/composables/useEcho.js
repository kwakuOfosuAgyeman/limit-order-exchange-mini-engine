import { ref, onMounted, onUnmounted } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useAuthStore } from '@/stores/auth'
import { useOrdersStore } from '@/stores/orders'

// Make Pusher available globally for Laravel Echo
window.Pusher = Pusher

let echoInstance = null

export function useEcho() {
  const connected = ref(false)
  const authStore = useAuthStore()
  const ordersStore = useOrdersStore()

  function initEcho() {
    if (echoInstance) {
      return echoInstance
    }

    echoInstance = new Echo({
      broadcaster: 'pusher',
      key: import.meta.env.VITE_PUSHER_APP_KEY,
      cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
      forceTLS: true,
      authEndpoint: `${import.meta.env.VITE_API_URL || 'http://localhost:8000/api'}/broadcasting/auth`,
      auth: {
        headers: {
          Authorization: `Bearer ${localStorage.getItem('token')}`,
        },
      },
    })

    return echoInstance
  }

  function subscribeToUserChannel(userId) {
    if (!echoInstance || !userId) return

    echoInstance
      .private(`user.${userId}`)
      .listen('.order.matched', (event) => {
        console.log('Order matched event received:', event)

        // Determine if current user is buyer or seller
        const isBuyer = event.buyer.id === authStore.user?.id

        // Update balances
        if (isBuyer) {
          authStore.updateBalance(event.buyer.balance, event.buyer.locked_balance)
          authStore.updateAssets(event.buyer.assets)
        } else {
          authStore.updateBalance(event.seller.balance, event.seller.locked_balance)
          authStore.updateAssets(event.seller.assets)
        }

        // Update order status
        const orderId = isBuyer ? event.buy_order_id : event.sell_order_id
        ordersStore.updateOrderStatus(orderId, 'filled')

        // Refresh orders and orderbook
        ordersStore.fetchOrders()
        ordersStore.fetchOrderBook(ordersStore.selectedSymbol)
      })

    connected.value = true
  }

  function unsubscribeFromUserChannel(userId) {
    if (!echoInstance || !userId) return

    echoInstance.leave(`private-user.${userId}`)
    connected.value = false
  }

  function disconnect() {
    if (echoInstance) {
      echoInstance.disconnect()
      echoInstance = null
      connected.value = false
    }
  }

  onMounted(() => {
    if (authStore.isAuthenticated && authStore.user?.id) {
      initEcho()
      subscribeToUserChannel(authStore.user.id)
    }
  })

  onUnmounted(() => {
    if (authStore.user?.id) {
      unsubscribeFromUserChannel(authStore.user.id)
    }
  })

  return {
    connected,
    initEcho,
    subscribeToUserChannel,
    unsubscribeFromUserChannel,
    disconnect,
  }
}
