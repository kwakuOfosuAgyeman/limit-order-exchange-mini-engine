# Limit Order Exchange Mini Engine

A full-stack limit order exchange system built with Laravel (API) and Vue.js (Frontend), featuring real-time updates via Pusher.

## Features

- **User Authentication**: Register, login, logout with Laravel Sanctum
- **Wallet Management**: USD balance and crypto asset balances
- **Limit Orders**: Create buy/sell orders with price and amount
- **Order Matching**: Full match only (exact amount matching)
- **Commission System**: 1.5% fee charged to buyer
- **Real-time Updates**: Balance and order updates via Pusher
- **Order Book**: Live buy/sell order display
- **Order History**: Track open, filled, and cancelled orders

## Technology Stack

- **Backend**: Laravel 12, PHP 8.2+
- **Frontend**: Vue.js 3 (Composition API), Vite, Tailwind CSS 4
- **Database**: MySQL
- **Real-time**: Pusher / Laravel Echo
- **Authentication**: Laravel Sanctum

## Project Structure

```
limit-order-exchange-mini-engine/
├── backend/               # Laravel API
│   ├── app/
│   │   ├── Enums/        # OrderSide, OrderStatus
│   │   ├── Events/       # OrderMatched
│   │   ├── Exceptions/   # Custom exceptions
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   ├── Requests/
│   │   │   └── Resources/
│   │   ├── Models/       # User, Order, Trade, etc.
│   │   └── Services/     # BalanceService, OrderService, MatchingEngine
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/
│   └── routes/
│       ├── api.php
│       └── channels.php
└── frontend/              # Vue.js SPA
    └── src/
        ├── api/          # Axios API clients
        ├── components/   # Vue components
        ├── composables/  # useEcho
        ├── stores/       # Pinia stores
        └── views/        # Login, Register, Dashboard
```

## Setup Instructions

### Prerequisites

- PHP 8.2+
- Composer
- Node.js 18+
- MySQL
- Pusher account (for real-time features)

### Backend Setup

1. Navigate to backend directory:
   ```bash
   cd backend
   ```

2. Install PHP dependencies:
   ```bash
   composer install
   ```

3. Copy environment file:
   ```bash
   cp .env.example .env
   ```

4. Configure `.env` file:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=exchange_db
   DB_USERNAME=root
   DB_PASSWORD=

   BROADCAST_CONNECTION=pusher

   PUSHER_APP_ID=your_app_id
   PUSHER_APP_KEY=your_key
   PUSHER_APP_SECRET=your_secret
   PUSHER_APP_CLUSTER=mt1
   ```

5. Generate application key:
   ```bash
   php artisan key:generate
   ```

6. Run migrations and seeders:
   ```bash
   php artisan migrate --seed
   ```

7. Start the development server:
   ```bash
   php artisan serve
   ```

The API will be available at `http://localhost:8000`

### Frontend Setup

1. Navigate to frontend directory:
   ```bash
   cd frontend
   ```

2. Install dependencies:
   ```bash
   npm install
   ```

3. Copy environment file:
   ```bash
   cp .env.example .env
   ```

4. Configure `.env` file:
   ```env
   VITE_API_URL=http://localhost:8000/api
   VITE_PUSHER_APP_KEY=your_pusher_key
   VITE_PUSHER_APP_CLUSTER=mt1
   ```

5. Start the development server:
   ```bash
   npm run dev
   ```

The frontend will be available at `http://localhost:5173`

## Test Users

After running seeders, two test users are available:

| Email | Password | USD Balance | BTC | ETH |
|-------|----------|-------------|-----|-----|
| alice@test.com | password | $100,000 | 1.0 | 10.0 |
| bob@test.com | password | $100,000 | 1.0 | 10.0 |

## API Endpoints

### Public
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/register | Register new user |
| POST | /api/login | User login |
| GET | /api/symbols | List trading pairs |
| GET | /api/orderbook/{symbol} | Get orderbook |

### Protected (Requires Authentication)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/logout | Logout |
| GET | /api/profile | Get user profile with balances |
| GET | /api/orders | List user's orders |
| POST | /api/orders | Create new order |
| GET | /api/orders/{uuid} | Get order details |
| POST | /api/orders/{uuid}/cancel | Cancel order |
| GET | /api/trades | Get trade history |

## Order Matching Rules

- **Full Match Only**: Orders only execute when a counter-order exists with the exact same amount
- **Price Matching**:
  - Buy order matches with sell orders where `sell.price <= buy.price`
  - Sell order matches with buy orders where `buy.price >= sell.price`
- **Execution Price**: Uses the maker's (resting order) price
- **Commission**: 1.5% deducted from buyer (USD)

## Real-time Events

The `OrderMatched` event is broadcast to both buyer and seller on their private channels:
- Channel: `private-user.{userId}`
- Event: `order.matched`
- Payload: Trade details, updated balances, order IDs

## Database Schema

### Core Tables
- `users` - User accounts with USD balance
- `assets` - User crypto holdings
- `orders` - Limit orders
- `trades` - Executed matches
- `balance_ledger` - Audit trail for all balance changes

### Supporting Tables
- `symbols` - Trading pairs (BTC, ETH)
- `fee_tiers` - Commission rates
- `system_settings` - Application configuration
- `order_status_history` - Order state changes

## Concurrency Safety

- **Optimistic Locking**: Version column on users and assets
- **Database Transactions**: All balance operations are atomic
- **Row-level Locks**: `FOR UPDATE` locks during matching
- **Idempotency Keys**: Prevent duplicate ledger entries

## License

MIT License
