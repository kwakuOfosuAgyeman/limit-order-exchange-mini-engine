import { ref, onMounted, onUnmounted, watch } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useAuthStore } from '@/stores/auth'
import { useOrdersStore } from '@/stores/orders'

// Make Pusher available globally for Laravel Echo
window.Pusher = Pusher

let echoInstance = null
// Track current orderbook subscription - we unsubscribe when switching symbols to:
// 1. Stay within Pusher connection/channel limits on lower-tier plans
// 2. Reduce memory usage from holding state for inactive channels
// 3. Minimize bandwidth from receiving updates for non-visible orderbooks
// If you need multi-symbol support, remove the unsubscribe logic and track all subscriptions in a Set
let currentOrderbookChannel = null

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

        // Refresh orders list
        ordersStore.fetchOrders()
      })

    connected.value = true
  }

  function subscribeToOrderbook(symbol) {
    if (!echoInstance || !symbol) return

    // Unsubscribe from previous channel if different
    if (currentOrderbookChannel && currentOrderbookChannel !== symbol) {
      echoInstance.leave(`orderbook.${currentOrderbookChannel}`)
    }

    currentOrderbookChannel = symbol

    echoInstance
      .channel(`orderbook.${symbol}`)
      .listen('.orderbook.updated', (event) => {
        console.log('Orderbook updated:', event)
        // Update the orderbook directly from the event data
        ordersStore.updateOrderBook(event)
      })

    console.log(`Subscribed to orderbook.${symbol}`)
  }

  function unsubscribeFromOrderbook(symbol) {
    if (!echoInstance || !symbol) return
    echoInstance.leave(`orderbook.${symbol}`)
    if (currentOrderbookChannel === symbol) {
      currentOrderbookChannel = null
    }
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
      currentOrderbookChannel = null
    }
  }

  // Watch for symbol changes and resubscribe
  watch(
    () => ordersStore.selectedSymbol,
    (newSymbol, oldSymbol) => {
      if (echoInstance && newSymbol !== oldSymbol) {
        if (oldSymbol) {
          unsubscribeFromOrderbook(oldSymbol)
        }
        subscribeToOrderbook(newSymbol)
      }
    }
  )

  onMounted(() => {
    initEcho()

    // Subscribe to user channel if authenticated
    if (authStore.isAuthenticated && authStore.user?.id) {
      subscribeToUserChannel(authStore.user.id)
    }

    // Subscribe to orderbook channel
    if (ordersStore.selectedSymbol) {
      subscribeToOrderbook(ordersStore.selectedSymbol)
    }
  })

  onUnmounted(() => {
    if (authStore.user?.id) {
      unsubscribeFromUserChannel(authStore.user.id)
    }
    if (currentOrderbookChannel) {
      unsubscribeFromOrderbook(currentOrderbookChannel)
    }
  })

  return {
    connected,
    initEcho,
    subscribeToUserChannel,
    unsubscribeFromUserChannel,
    subscribeToOrderbook,
    unsubscribeFromOrderbook,
    disconnect,
  }
}
