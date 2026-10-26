# Backend Product Requirements Document (PRD): Category Management API

**Product:** Expense Tracker Backend  
**Module:** Category Management System  
**Version:** 1.1.0  
**Status:** Approved & Implemented  
**Target Environment:** PHP 8.1+ (Slim 4 Framework) with MongoDB Storage  
**Base URL:** `http://localhost:8000/api`  

---

## 1. Executive Summary & Objectives

The Category Management API provides a secure, multi-tenant CRUD interface for managing expense and income categories. Categories are essential metadata tags associated with financial transactions, enabling budgeting, category-wise expense breakdown, analytics, and visual differentiation across the client dashboard.

### Core Objectives:
- **Full CRUD Support:** Allow authenticated users to create, view, modify, and delete custom categories.
- **Frontend Compatibility:** Directly align data schemas with the React frontend UI (`Expense/src/pages/categories.jsx`), supporting customizable icons (`iconName`), theme color badges (`color`), and monthly spending allocations (`budget` / `baseAmount`).
- **Real-Time Spending Metrics:** Return computed metrics (`total_spent`, `tx_count`, `remaining_budget`, `spent_percentage`) automatically when listing and fetching category details.
- **Data Integrity & Relational Safety:** Prevent inadvertent loss of financial transaction history by blocking category deletion when linked transactions exist (unless explicit `?force=true` cascade unlinking is requested).
- **Multi-Tenancy & Isolation:** Enforce strict user separation via JWT Bearer authentication.

---

## 2. Architecture & Data Model

### 2.1 Collection: `categories`
Each document in MongoDB represents a distinct category owned by a specific user.

| Field Name | Type | Required | Default | Description |
|---|---|---|---|---|
| `_id` | String (UUID v4) | Yes | Auto | Unique identifier of the category |
| `id` | String (UUID v4) | Yes | Auto | Mirror of `_id` for client convenience |
| `user_fk` | String (UUID v4) | Yes | - | Identifier of the owning user (from JWT) |
| `name` | String (1-100 chars) | Yes | - | Category label (e.g. `"Food & Dining"`, `"Transport"`) |
| `iconName` | String | No | `"Utensils"` | Icon identifier matching frontend Lucide icon names |
| `color` | String | No | `"green"` | Color identifier (e.g. `green`, `blue`, `purple`, `amber`, `rose`, etc.) |
| `budget` | Float | No | `0.00` | Monthly spending limit assigned to this category |
| `baseAmount` | Float | No | `0.00` | Mirror of `budget` (for legacy frontend backwards-compatibility) |
| `created_at` | String (Datetime) | Yes | `Y-m-d H:i:s` | ISO/SQL timestamp of record creation |
| `updated_at` | String (Datetime) | No | `Y-m-d H:i:s` | Timestamp of last modification |

### 2.2 Computed Metrics (Response Enriched)
When categories are retrieved, the backend aggregates live records from the `transactions` collection (`user_fk = current_user` and `category_fk = category._id`):

- `tx_count`: Integer — Total number of transactions assigned to this category.
- `total_spent`: Float — Sum of all transactions of type `"expense"` in this category.
- `remaining_budget`: Float — `max(0, budget - total_spent)`.
- `spent_percentage`: Float — `(total_spent / budget) * 100` (capped at 100%).

---

## 3. Authentication & Security

All Category endpoints require a valid JWT token passed in the `Authorization` header:

```http
Authorization: Bearer <JWT_TOKEN>
```

- If missing or invalid, the API returns `401 Unauthorized`.
- All database queries filter strictly on `user_fk` extracted from the verified token payload. A user can never read, modify, or delete another user's categories.

---

## 4. API Endpoints Specification

### Summary Matrix

| Method | Endpoint | Description | Auth Required |
|---|---|---|---|
| `GET` | `/api/categories` | Retrieve all categories with spending metrics | Yes |
| `POST` | `/api/categories` | Create a new custom category | Yes |
| `GET` | `/api/categories/{id}` | Retrieve single category details with metrics | Yes |
| `PUT` | `/api/categories/{id}` | Update category fields (name, icon, color, budget) | Yes |
| `DELETE` | `/api/categories/{id}` | Safely delete a category (or force unlink with `?force=true`) | Yes |

---

### 4.1 List All Categories
Retrieves all categories for the logged-in user, sorted alphabetically by name, enriched with live spending calculations.

- **URL:** `GET /api/categories`
- **Headers:**
  - `Authorization: Bearer <TOKEN>`
  - `Content-Type: application/json`

#### cURL Request:
```bash
curl -X GET "http://localhost:8000/api/categories" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json"
```

#### Success Response (200 OK):
```json
{
  "success": true,
  "message": "Categories retrieved successfully",
  "data": [
    {
      "_id": "67b84d9f-a2e1-4321-bf99-123456789abc",
      "id": "67b84d9f-a2e1-4321-bf99-123456789abc",
      "name": "Food & Dining",
      "iconName": "Utensils",
      "color": "green",
      "budget": 500.0,
      "baseAmount": 500.0,
      "user_fk": "usr-889922",
      "created_at": "2026-08-20 10:15:00",
      "updated_at": "2026-08-20 10:15:00",
      "tx_count": 8,
      "total_spent": 245.5,
      "remaining_budget": 254.5,
      "spent_percentage": 49.1
    },
    {
      "_id": "78c95eaf-b3f2-4432-cf00-234567890def",
      "id": "78c95eaf-b3f2-4432-cf00-234567890def",
      "name": "Transport",
      "iconName": "Car",
      "color": "blue",
      "budget": 200.0,
      "baseAmount": 200.0,
      "user_fk": "usr-889922",
      "created_at": "2026-08-21 11:30:00",
      "updated_at": "2026-08-21 11:30:00",
      "tx_count": 3,
      "total_spent": 65.0,
      "remaining_budget": 135.0,
      "spent_percentage": 32.5
    }
  ]
}
```

---

### 4.2 Create New Category
Creates a new category for the current user. Validates name presence, maximum length, budget numeric bounds, and verifies uniqueness for the user.

- **URL:** `POST /api/categories`
- **Headers:**
  - `Authorization: Bearer <TOKEN>`
  - `Content-Type: application/json`

#### Request Body Parameters:
| Property | Type | Required | Description | Example |
|---|---|---|---|---|
| `name` | String | Yes | Category title | `"Shopping & Lifestyle"` |
| `iconName` | String | No | Lucide icon name | `"ShoppingBag"` |
| `color` | String | No | Palette color key | `"purple"` |
| `budget` | Number | No | Monthly budget allocation | `350.00` |

#### cURL Request:
```bash
curl -X POST "http://localhost:8000/api/categories" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Shopping & Lifestyle",
    "iconName": "ShoppingBag",
    "color": "purple",
    "budget": 350.00
  }'
```

#### Success Response (201 Created):
```json
{
  "success": true,
  "message": "Category created successfully",
  "data": {
    "_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "name": "Shopping & Lifestyle",
    "iconName": "ShoppingBag",
    "color": "purple",
    "budget": 350.0,
    "baseAmount": 350.0,
    "user_fk": "usr-889922",
    "created_at": "2026-08-22 14:00:00",
    "updated_at": "2026-08-22 14:00:00",
    "tx_count": 0,
    "total_spent": 0.0,
    "remaining_budget": 350.0,
    "spent_percentage": 0
  }
}
```

#### Duplicate Error Response (409 Conflict):
```json
{
  "success": false,
  "message": "Category with this name already exists",
  "data": null
}
```

#### Validation Error (422 Unprocessable Entity):
```json
{
  "success": false,
  "message": "Validation error",
  "data": {
    "name": "Category name is required"
  }
}
```

---

### 4.3 Get Single Category Details
Fetches an individual category record along with calculated spend and transaction counts.

- **URL:** `GET /api/categories/{id}`
- **Headers:**
  - `Authorization: Bearer <TOKEN>`
  - `Content-Type: application/json`

#### cURL Request:
```bash
curl -X GET "http://localhost:8000/api/categories/a1b2c3d4-e5f6-7890-abcd-ef1234567890" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json"
```

#### Success Response (200 OK):
```json
{
  "success": true,
  "message": "Category details fetched",
  "data": {
    "_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "name": "Shopping & Lifestyle",
    "iconName": "ShoppingBag",
    "color": "purple",
    "budget": 350.0,
    "baseAmount": 350.0,
    "user_fk": "usr-889922",
    "created_at": "2026-08-22 14:00:00",
    "updated_at": "2026-08-22 14:00:00",
    "tx_count": 2,
    "total_spent": 84.2,
    "remaining_budget": 265.8,
    "spent_percentage": 24.1
  }
}
```

#### Not Found (404 Not Found):
```json
{
  "success": false,
  "message": "Category not found",
  "data": null
}
```

---

### 4.4 Update Existing Category
Allows partial or full updates to a category. Only the fields provided in the body will be modified.

- **URL:** `PUT /api/categories/{id}`
- **Headers:**
  - `Authorization: Bearer <TOKEN>`
  - `Content-Type: application/json`

#### Request Body (Any combination of):
```json
{
  "name": "Shopping & Fashion",
  "iconName": "ShoppingBag",
  "color": "rose",
  "budget": 450.00
}
```

#### cURL Request:
```bash
curl -X PUT "http://localhost:8000/api/categories/a1b2c3d4-e5f6-7890-abcd-ef1234567890" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Shopping & Fashion",
    "color": "rose",
    "budget": 450.00
  }'
```

#### Success Response (200 OK):
```json
{
  "success": true,
  "message": "Category updated successfully",
  "data": {
    "_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "name": "Shopping & Fashion",
    "iconName": "ShoppingBag",
    "color": "rose",
    "budget": 450.0,
    "baseAmount": 450.0,
    "user_fk": "usr-889922",
    "created_at": "2026-08-22 14:00:00",
    "updated_at": "2026-08-22 15:30:00"
  }
}
```

---

### 4.5 Delete Category

#### 4.5.1 Safe Deletion (Default)
If the category is currently referenced by transactions, the backend blocks the deletion to protect history and returns HTTP 409 Conflict.

- **URL:** `DELETE /api/categories/{id}`

#### cURL Request (Safe):
```bash
curl -X DELETE "http://localhost:8000/api/categories/a1b2c3d4-e5f6-7890-abcd-ef1234567890" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json"
```

#### Blocked Conflict Response (409 Conflict):
```json
{
  "success": false,
  "message": "Cannot delete category: 4 transaction(s) are currently associated with it. Please reassign or delete these transactions first, or append '?force=true' to disassociate them and delete.",
  "data": {
    "linked_transactions_count": 4,
    "category_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
  }
}
```

#### 4.5.2 Force Deletion (`?force=true`)
When `?force=true` is passed as a query parameter, the backend automatically sets `category_fk = null` on all linked transactions and then removes the category document.

#### cURL Request (Force):
```bash
curl -X DELETE "http://localhost:8000/api/categories/a1b2c3d4-e5f6-7890-abcd-ef1234567890?force=true" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json"
```

#### Success Response (200 OK):
```json
{
  "success": true,
  "message": "Category deleted successfully",
  "data": {
    "deleted_category_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "unlinked_transactions_count": 4
  }
}
```

---

## 5. Frontend Client Integration (`categories.jsx`)

Here is how the React client communicates directly with this API:

```javascript
// Base configuration
const API_BASE = "http://localhost:8000/api";
const getAuthHeaders = () => ({
  "Content-Type": "application/json",
  "Authorization": `Bearer ${localStorage.getItem("authToken")}`
});

// 1. Fetch Categories
export const fetchCategories = async () => {
  const res = await fetch(`${API_BASE}/categories`, {
    headers: getAuthHeaders()
  });
  const json = await res.json();
  if (!res.ok) throw new Error(json.message);
  return json.data; // Array of categories with live stats
};

// 2. Create Category
export const createCategory = async ({ name, iconName, color, budget }) => {
  const res = await fetch(`${API_BASE}/categories`, {
    method: "POST",
    headers: getAuthHeaders(),
    body: JSON.stringify({ name, iconName, color, budget: parseFloat(budget) || 0 })
  });
  const json = await res.json();
  if (!res.ok) throw new Error(json.message);
  return json.data;
};

// 3. Update Category
export const updateCategory = async (id, { name, iconName, color, budget }) => {
  const res = await fetch(`${API_BASE}/categories/${id}`, {
    method: "PUT",
    headers: getAuthHeaders(),
    body: JSON.stringify({ name, iconName, color, budget: parseFloat(budget) || 0 })
  });
  const json = await res.json();
  if (!res.ok) throw new Error(json.message);
  return json.data;
};

// 4. Delete Category
export const deleteCategory = async (id, force = false) => {
  const url = `${API_BASE}/categories/${id}${force ? '?force=true' : ''}`;
  const res = await fetch(url, {
    method: "DELETE",
    headers: getAuthHeaders()
  });
  const json = await res.json();
  if (!res.ok) throw new Error(json.message);
  return json.data;
};
```

---

## 6. Testing & Validation Checklist

- [x] Unauthenticated requests return `401 Unauthorized`.
- [x] Category creation with empty `name` returns `422 Unprocessable Entity`.
- [x] Creating duplicate category name for the same user returns `409 Conflict`.
- [x] Same category name allowed across distinct users (multi-tenant boundary intact).
- [x] Updating category checks uniqueness excluding the current category ID.
- [x] Deleting a category with active transactions returns 409 unless `?force=true` is provided.
- [x] `index` and `show` endpoints dynamically return accurate `total_spent` and `tx_count`.
