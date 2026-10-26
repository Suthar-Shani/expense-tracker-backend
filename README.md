# Expense Tracker PHP REST API Backend

A modern, high-performance **PHP REST API Backend** built with **Composer**, **Slim 4 Framework**, **JWT Authentication**, and **SQLite/MySQL PDO Database**. Designed to pair seamlessly with React or any frontend framework for Expense Management & Analytics.

---

## Features

- 🔐 **Authentication System**: User registration, secure login with bcrypt password hashing, and JWT Bearer token issuance.
- 💼 **User Data Isolation**: Expenses, income records, and financial summary analytics are completely isolated per user account.
- 📊 **Financial Summary & Analytics**: Real-time balance calculation (`Total Income - Total Expense`), highest expense tracking, and category breakdown percentages.
- ⚡ **Full Expense CRUD**: Create, read (with search, category filter, date range, sorting), update, and delete expense/income records.
- 🌐 **CORS Preflight Middleware**: Out-of-the-box support for cross-origin requests from React applications (`http://localhost:3000`, `http://localhost:5173`, etc.).
- 🗄️ **Zero-Config Database**: Defaults to SQLite for instant local development without setup, while fully supporting MySQL.

---

## API Documentation

### Base URL
`http://localhost:8000`

### Authentication Endpoints (Public)

| Method | Endpoint | Description | Request Body |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/auth/register` | Register a new user | `{ "name": "John", "email": "john@example.com", "password": "secretpassword" }` |
| `POST` | `/api/auth/login` | Login and receive JWT token | `{ "email": "john@example.com", "password": "secretpassword" }` |

### Protected Endpoints (Requires Header: `Authorization: Bearer <token>`)

| Method | Endpoint | Description | Query Parameters / Body |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/auth/me` | Get active user profile | None |
| `GET` | `/api/expenses` | List user expenses | `?category=Food%20%26%20Dining&search=grocery&type=expense&sort_by=amount&sort_order=DESC` |
| `GET` | `/api/expenses/summary` | Get financial metrics & stats | None |
| `GET` | `/api/expenses/{id}` | Get single expense details | None |
| `POST` | `/api/expenses` | Create income or expense | `{ "title": "Salary", "amount": 5000, "type": "income", "category": "Salary/Income", "date": "2026-08-22", "notes": "Monthly pay" }` |
| `PUT` | `/api/expenses/{id}` | Update existing expense | `{ "title": "Updated Title", "amount": 120, ... }` |
| `DELETE` | `/api/expenses/{id}` | Delete an expense | None |

### Dashboard Endpoints (Protected: `Authorization: Bearer <token>`)

| Method | Endpoint | Description | Query Parameters |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/dashboard` | **Master Dashboard**: All 4 sections in 1 payload | `?month=YYYY-MM&months=6&limit=5` |
| `GET` | `/api/dashboard/summary` | **Section 1**: KPI Summary Cards (Balance, Income, Expenses, % Changes) | `?month=YYYY-MM` (e.g. `2026-10`) |
| `GET` | `/api/dashboard/spending-overview` | **Section 2**: Monthly Income vs Expenses Chart data | `?months=6` (default 6, up to 24) |
| `GET` | `/api/dashboard/expense-breakdown` | **Section 3**: Top spending categories & donut chart data | `?month=YYYY-MM` or `?month=all` |
| `GET` | `/api/dashboard/recent-transactions` | **Section 4**: Recent transactions table enriched with categories/accounts | `?limit=5` (default 5, up to 50) |

---

## Quick Start Guide

### 1. Requirements
- PHP >= 8.0 (with `pdo_sqlite` or `pdo_mysql` enabled)
- Composer

### 2. Install Dependencies
```bash
composer install
```

### 3. Initialize Database & Seed Demo Data
```bash
php database/migrate.php
```

*Demo Account Credentials:*
- **Email**: `demo@example.com`
- **Password**: `password123`

### 4. Start Local Development Server
```bash
php -S 127.0.0.1:8000 -t public
```

---

## Running with Docker

You can run the entire backend using Docker or Docker Compose without installing PHP or Composer locally:

### Option A: Using Docker Compose (Recommended)
```bash
# Start the container in detached mode
docker compose up -d --build

# View container logs
docker compose logs -f

# Stop the container
docker compose down
```

### Option B: Using Standalone Docker Commands
```bash
# Build the Docker image
docker build -t expense-tracker-backend .

# Run container with environment file and port mapping
docker run -d -p 8000:80 --env-file .env --name expense_api expense-tracker-backend
```

The API will be available at `http://localhost:8000`.

---

## Deploy to Render (Live Deployment)

This project is Docker-ready for deployment on **[Render.com](https://render.com)**.

### Method 1: Render Web Service (Docker)
1. Push your code to GitHub (`git push origin master`).
2. Go to [dashboard.render.com](https://dashboard.render.com) and click **New +** -> **Web Service**.
3. Connect your GitHub repository: `Suthar-Shani/expense-tracker-backend`.
4. Render will automatically detect the **Docker** runtime.
5. In **Environment Variables**, add:
   - `MONGO_URI`: `mongodb+srv://...` (your MongoDB connection string)
   - `MONGO_DB`: `explanse_db`
   - `JWT_SECRET`: (Click *Generate* or provide a random 32-character string)
   - `CORS_ALLOWED_ORIGINS`: `*` (or your frontend domain, e.g. `https://your-frontend.vercel.app`)
6. Click **Deploy Web Service**.

### Method 2: Render Blueprint (1-Click)
1. Go to [dashboard.render.com](https://dashboard.render.com) and click **New +** -> **Blueprint**.
2. Select your repository.
3. Render reads `render.yaml` automatically. Enter your `MONGO_URI` when prompted and click **Apply**.

---

## React Integration Quick Guide

To connect your React frontend to this PHP backend:

```js
// Example React fetch service
const API_URL = 'http://localhost:8000/api';

export const login = async (email, password) => {
  const res = await fetch(`${API_URL}/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password })
  });
  return res.json();
};

export const getExpenses = async (token) => {
  const res = await fetch(`${API_URL}/expenses`, {
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    }
  });
  return res.json();
};
```
