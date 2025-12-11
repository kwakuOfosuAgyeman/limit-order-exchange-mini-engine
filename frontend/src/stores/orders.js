import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { ordersApi } from '@/api/orders'

export const useOrdersStore = defineStore('orders', () => {
  const orders = ref([])
  const trades = ref([])
  const orderBook = ref({ bids: [], asks: [] })
  const symbols = ref([])
  const selectedSymbol = ref('BTC')
  const loading = ref(false)
  const error = ref(null)

  const openOrders = computed(() =>
    orders.value.filter((o) => ['open', 'partially_filled'].includes(o.status))
  )

  const filledOrders = computed(() =>
    orders.value.filter((o) => o.status === 'filled')
  )

  const cancelledOrders = computed(() =>
    orders.value.filter((o) => o.status === 'cancelled')
  )

  async function fetchOrders(params = {}) {
    loading.value = true
    error.value = null
    try {
      const data = await ordersApi.getOrders(params)
      orders.value = data.orders
      return data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch orders'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createOrder(symbol, side, price, amount) {
    loading.value = true
    error.value = null
    try {
      const data = await ordersApi.createOrder(symbol, side, price, amount)
      // Add the new order to the list
      orders.value.unshift(data.order)
      return data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create order'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function cancelOrder(orderId) {
    loading.value = true
    error.value = null
    try {
      const data = await ordersApi.cancelOrder(orderId)
      // Update the order in the list
      const index = orders.value.findIndex((o) => o.id === orderId)
      if (index !== -1) {
        orders.value[index] = data.order
      }
      return data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to cancel order'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchOrderBook(symbol) {
    try {
      const data = await ordersApi.getOrderBook(symbol)
      orderBook.value = data
      return data
    } catch (err) {
      console.error('Failed to fetch orderbook:', err)
    }
  }

  async function fetchTrades(params = {}) {
    try {
      const data = await ordersApi.getTrades(params)
      trades.value = data.trades
      return data
    } catch (err) {
      console.error('Failed to fetch trades:', err)
    }
  }

  async function fetchSymbols() {
    try {
      const data = await ordersApi.getSymbols()
      symbols.value = data.symbols
      return data
    } catch (err) {
      console.error('Failed to fetch symbols:', err)
    }
  }

  function updateOrderStatus(orderId, newStatus) {
    const order = orders.value.find((o) => o.id === orderId)
    if (order) {
      order.status = newStatus
      order.status_label = newStatus.charAt(0).toUpperCase() + newStatus.slice(1)
    }
  }

  function updateOrderBook(data) {
    // Update orderbook from WebSocket event
    orderBook.value = {
      bids: data.bids || [],
      asks: data.asks || [],
    }
  }

  function setSelectedSymbol(symbol) {
    selectedSymbol.value = symbol
  }

  return {
    orders,
    trades,
    orderBook,
    symbols,
    selectedSymbol,
    loading,
    error,
    openOrders,
    filledOrders,
    cancelledOrders,
    fetchOrders,
    createOrder,
    cancelOrder,
    fetchOrderBook,
    fetchTrades,
    fetchSymbols,
    updateOrderStatus,
    updateOrderBook,
    setSelectedSymbol,
  }
})
