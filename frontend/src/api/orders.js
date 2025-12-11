import api from './index'

export const ordersApi = {
  async getOrders(params = {}) {
    const response = await api.get('/orders', { params })
    return response.data
  },

  async createOrder(symbol, side, price, amount) {
    const response = await api.post('/orders', {
      symbol,
      side,
      price,
      amount,
    })
    return response.data
  },

  async cancelOrder(orderId) {
    const response = await api.post(`/orders/${orderId}/cancel`)
    return response.data
  },

  async getOrderBook(symbol) {
    const response = await api.get(`/orderbook/${symbol}`)
    return response.data
  },

  async getTrades(params = {}) {
    const response = await api.get('/trades', { params })
    return response.data
  },

  async getSymbols() {
    const response = await api.get('/symbols')
    return response.data
  },
}
