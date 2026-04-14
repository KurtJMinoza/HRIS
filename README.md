# HRIS (Human Resource Information System)

Full-stack **HRIS** for workforce, attendance, payroll, and employee self-service — built with a **React (Vite)** SPA and a **Laravel** REST API backed by **MySQL**.

**Repository:** [github.com/KurtJMinoza/HRIS](https://github.com/KurtJMinoza/HRIS)

---

## Overview

This application supports **multi-company / multi-branch** HR operations: employee records, organizational structure, time and attendance (including **face-verified** clock in/out), leave and overtime workflows, compensation and deductions, **Philippine payroll** concepts (SSS, PhilHealth, Pag-IBIG, withholding), payslips, loans, benefits, documents, and **role-based access control (RBAC)** for HR admins vs employees.

### Main capabilities

| Area | Highlights |
|------|------------|
| **Identity & access** | Laravel Sanctum (Bearer tokens), SPA-friendly CORS, admin vs employee roles, granular HR permissions |
| **Attendance** | Clock in/out, schedules, corrections, monitoring, SmartDTR / kiosk-style flows with face checks |
| **Leave & overtime** | Requests, approvals, audits, attachments where applicable |
| **Payroll** | Pay cycles, pay components, daily computation logs, batch runs, finalization jobs, payslip PDF generation |
| **People data** | Profiles, government IDs, emergency contacts, skills, certifications, documents |
| **Loans & deductions** | Employee loans, amortization, deduction schedules aligned with payroll runs |

### Tech stack

- **Frontend:** React, Vite, Tailwind CSS, TanStack Query, client-side routing  
- **Backend:** PHP 8.x, Laravel, queues/jobs for heavy payroll and report work  
- **Database:** MySQL (migrations under `backend/database/migrations`)  
- **Auth:** Token-based API auth (see below)

---

## Repository layout

```
HR/
├── frontend/     # React SPA (Vite)
├── backend/      # Laravel API
├── face_service/ # Optional / auxiliary face-related tooling (if present)
├── package.json  # Root workspace helpers (if used)
└── README.md
```

---

## Prerequisites

- **PHP** ≥ 8.2 (with extensions typical for Laravel: `pdo_mysql`, `mbstring`, `openssl`, `curl`, etc.)
- **Composer**
- **Node.js** LTS + **npm**
- **MySQL** 8.x (or compatible)

---

## Quick start

### 1. Database

Create a database (example name `hris`):

```sql
CREATE DATABASE hris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Backend (Laravel)

```bash
cd backend
cp .env.example .env
php artisan key:generate
```

Configure `.env` with `DB_*` credentials, `APP_URL`, mail/SMS keys as needed, then:

```bash
composer install
php artisan migrate
# Optional: seed admin / demo data (see seeders)
php artisan db:seed --class=AdminUserSeeder   # if you use the bundled admin seeder
php artisan serve
```

API default: `http://localhost:8000` — routes are typically under `/api`.

### 3. Frontend (React)

```bash
cd frontend
npm install
cp .env.example .env   # if present; set VITE_API_URL
npm run dev
```

SPA default: `http://localhost:5173` (Vite).

Point `VITE_API_URL` at your API base (e.g. `http://localhost:8000/api`).

### 4. Queue worker (recommended for payroll / PDF / mail)

```bash
cd backend
php artisan queue:work
```

---

## Authentication (Sanctum, token-only)

The SPA uses **Bearer tokens** (no cookie CSRF coupling across origins). Login/register return a token; the client sends `Authorization: Bearer <token>` on protected routes.

- Configure token lifetime via `SANCTUM_TOKEN_EXPIRATION` in `backend/.env` if needed.
- Set `CORS_ALLOWED_ORIGINS` (or equivalent) for your frontend origin in production.

---

## Face verification (SmartDTR)

Face enrollment and verification use:

- **AWS Rekognition** for server-side face matching and descriptor handling
- **AWS Amplify Face Liveness** for anti-spoofing and session liveness checks
- **DeepFace** services for additional face-processing and verification workflows

The `frontend/public/models/` folder is kept for legacy compatibility notes only and is not part of the primary production verification pipeline.

---

## Security & production checklist

- Never commit `.env` or production secrets; use `.env.example` as a template only.
- Change default seeded admin passwords before go-live.
- Use HTTPS, restrict CORS, and run `php artisan config:cache` / `route:cache` in production.
- Back up the database and stored uploads (`storage`) according to your retention policy.

---

## Scripts (root)

If the repository root defines npm scripts (e.g. concurrently running API + UI), see root `package.json`. Otherwise run backend and frontend in separate terminals as above.

---

## License

Specify your license here (e.g. MIT, proprietary). Default: **all rights reserved** until you add a `LICENSE` file.

---

## Author

**KurtJMinoza** — [github.com/KurtJMinoza](https://github.com/KurtJMinoza)
