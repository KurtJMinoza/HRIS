# HRIS Amalgated Company

HR Information System with React frontend and Laravel + MySQL backend.

## Project structure

- `frontend/` — React + Vite + Tailwind
- `backend/` — Laravel API with MySQL

## Setup

### 1. Database (MySQL)

Create the database:

```sql
CREATE DATABASE hris;
```

Or via phpMyAdmin: create a new database named `hris`.

### 2. Backend (Laravel)

```bash
cd backend
cp .env.example .env   # if needed
php artisan key:generate
php artisan migrate
php artisan serve
```

Backend runs at `http://localhost:8000`.

### 3. Frontend (React)

```bash
cd frontend
npm install
npm run dev
```

Frontend runs at `http://localhost:5173`.

### 4. API URL

The frontend uses `frontend/.env` and expects the backend at `http://localhost:8000/api` by default.

- **If you see "Failed to fetch" or "Cannot connect to the server"** on login/sign up: start the backend with `php artisan serve` in the `backend` folder so it runs at http://localhost:8000.
- If the backend runs on a different host/port, edit `frontend/.env` and set `VITE_API_URL` (e.g. `VITE_API_URL=http://localhost:8000/api`).

## Sanctum SPA authentication

The API uses **Laravel Sanctum** with **token-only** auth (no cookies) to avoid CSRF mismatch when the SPA runs on a different origin (e.g. frontend on `:5173`, backend on `:8000`).

- **Token-based**: Login and register return a Bearer token; the frontend stores it and sends `Authorization: Bearer <token>` on protected requests.
- **Protected routes** use the `auth:sanctum` middleware (`/api/logout`, `/api/user`, `/api/admin/*`).
- **CORS** is configured for the frontend origin(s); set `CORS_ALLOWED_ORIGINS` in backend `.env` if your app URL differs.
- **Token expiration** is set in backend `.env` via `SANCTUM_TOKEN_EXPIRATION` (minutes; default 7 days). Use `null` for no expiry.

Frontend helpers:

- `login()` / `register()` store the token and return `{ user, token }`; `user` includes `role` (`employee` or `admin`).
- `logout()` calls the API to revoke the token and clears local storage.
- `getAuthenticatedUser()` calls `GET /api/user` and returns the user (with `role`) or `null` (clears token on 401).
- `authenticatedFetch(path, options)` sends the Bearer token and clears it on 401 for use with any protected endpoint.

## Roles (employee vs admin)

- **Register (Create account)**: New users are created with role **`employee`**. They can sign in and use employee-facing features.
- **Admin**: Admins are not created via the public sign-up form. Create an admin by running:
  ```bash
  cd backend
  php artisan db:seed --class=AdminUserSeeder
  ```
  This creates a user with email `admin@amalgated.co`, password `admin` (change in production). You can edit `database/seeders/AdminUserSeeder.php` to use a different email/password or run it multiple times with different env vars.
- **Admin-only API routes** are protected with the `admin` middleware (e.g. `GET /api/admin/dashboard`). The frontend can use `user.role === 'admin'` to show or gate admin UI.

## Face recognition (SmartDTR)

The app uses **face-api.js** for:

- **Login**: If an employee has a face enrolled, they must pass face verification after password.
- **Admin → Employees**: "Capture face" stores a 128D descriptor (used for verification).
- **Employee → My Attendance**: Clock in/out requires face verification before recording.

**Models required:** Place face-api.js model files in `frontend/public/models/` so the app can load them. See `frontend/public/models/README.md` for the exact files (from [face-api.js-models](https://github.com/justadudewhohacks/face-api.js-models)). Without these, face capture and verification will show a loading error.

- Verification is done **server-side**: the frontend sends the 128D descriptor; the backend compares it to the stored one (Euclidean distance ≤ 0.6).

## API endpoints

| Method | Endpoint               | Description           |
|--------|------------------------|-----------------------|
| POST   | /api/register          | Create account (role: employee) |
| POST   | /api/login             | Sign in (returns `user.has_face` if face verification required) |
| POST   | /api/logout            | Sign out (auth)      |
| GET    | /api/user              | Current user (auth)  |
| POST   | /api/auth/verify-face  | Face verification after login (auth, body: `face_descriptor` [128 floats]) |
| POST   | /api/attendance        | Clock in/out with face (auth, body: `type`, `face_descriptor`) |
| GET    | /api/attendance        | List my attendance logs (auth) |
| GET    | /api/admin/dashboard   | Example admin-only (auth + admin) |
