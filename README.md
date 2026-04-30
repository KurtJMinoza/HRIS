# HRIS — Human Resource Information System

Full-stack **HRIS** for workforce management, attendance (including face-verified DTR), payroll, and employee self-service. The app is a **React (Vite)** SPA talking to a **Laravel 12** REST API, with optional **Python (FastAPI + DeepFace)** for face embeddings and integrations with **Amazon Rekognition** / **Amplify Face Liveness**.

**Repository:** [github.com/KurtJMinoza/HRIS](https://github.com/KurtJMinoza/HRIS)

---

## Tech stack (versions — see lockfiles for exact pins)

### Runtime & language

| Layer | Technology | Notes |
|--------|------------|--------|
| **PHP** | **8.2+** (`^8.2` in Composer) | Required extensions: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `intl` (as needed) |
| **Node.js** | **20+ LTS** recommended | Used for Vite build and frontend tooling |
| **Python** | **3.10+** recommended | For `face_service/` (FastAPI + DeepFace) |

### Backend (`backend/`)

| Package / area | Version (constraint / lock) | Role |
|----------------|----------------------------|------|
| **Laravel Framework** | **12.x** (lock: **v12.52.0**) | HTTP API, queues, scheduling, Eloquent ORM |
| **Laravel Sanctum** | **^4.3** (lock: **v4.3.1**) | SPA/API Bearer token authentication |
| **Laravel Octane** | **^2.17** | High-performance app server (optional in production) |
| **AWS SDK for PHP** | **^3.371** | Rekognition (Face Liveness, face search), S3, etc. |
| **barryvdh/laravel-dompdf** | * | Payslip / PDF generation |
| **Spatie Browsershot** | **^5.2** | Headless Chromium PDFs where used |
| **Twilio SDK** | **^8.11** | SMS / OTP flows |
| **tecnickcom/tcpdf** / **setasign/fpdi** | * | Additional PDF tooling |
| **PHPUnit** | **^11.5** (dev) | Tests |
| **Laravel Pint** | **^1.24** (dev) | Code style |

**Patterns:** REST JSON under `/api`, Sanctum tokens, queue workers for payroll batches, face registration jobs, mail, and heavy reports. MySQL for persistence.

### Frontend (`frontend/`)

| Package | Version (package.json) | Role |
|---------|------------------------|------|
| **React** | **^19.2** | UI |
| **React DOM** | **^19.2** | Rendering |
| **Vite** | **^7.3** | Dev server & production build |
| **@vitejs/plugin-react** | **^5.1** | React Fast Refresh |
| **React Router** | **^7.13** | Client-side routing (`BrowserRouter`) |
| **TanStack Query** | **^5.96** | Server state / caching |
| **TanStack Table** | **^8.21** | Data grids |
| **Tailwind CSS** | **^4.2** | Utility-first styling (`@tailwindcss/postcss`) |
| **Radix UI** / **radix-ui** | **^1.x** | Accessible primitives |
| **class-variance-authority** + **clsx** + **tailwind-merge** | latest ^ | Component variants |
| **AWS Amplify** | **^6.16** | AWS integration |
| **@aws-amplify/ui-react** | **^6.15** | Amplify UI components |
| **@aws-amplify/ui-react-liveness** | **^3.3** | **Amazon Rekognition Face Liveness** (guided liveness) |
| **axios** | **^1.13** | HTTP client (alongside `fetch` wrappers) |
| **date-fns** | **^4.1** | Date utilities |
| **Zod** | **^4.3** | Schema validation |
| **Recharts** | **^3.7** | Charts |
| **ExcelJS** | **^4.4** | Excel export |
| **@react-pdf/renderer** | **^4.3** | Client-side PDF previews where used |
| **face-api.js** / **@mediapipe/face_mesh** | legacy / auxiliary | Legacy or auxiliary face UI (primary liveness is Rekognition + backend) |
| **QR / scanning** | **qrcode.react**, **@zxing/browser** | QR codes for attendance |
| **Framer Motion / Motion** | **^12.x** | Animation |
| **Sonner** | **^2.x** | Toasts |
| **ESLint** | **^9.39** | Linting |

**UI:** Employee dashboard, HR admin panel, org-scoped panels (company / branch / department heads), kiosk-style flows, payslip viewers, and reports.

### Face embedding service (`face_service/`)

| Technology | Role |
|------------|------|
| **FastAPI** (`>=0.104`) | HTTP API (`uvicorn`) |
| **DeepFace** (`>=0.0.79`) | **Facenet**-style **128D embeddings** (ArcFace model configurable from Laravel) |
| **OpenCV** (`>=4.8`), **NumPy** | Image preprocessing |
| **Endpoints** | `/embed`, `/verify` (legacy), `/health` — used after Rekognition liveness supplies a reference image |

Liveness and anti-spoofing are handled by **Amazon Rekognition Face Liveness** (Amplify **FaceLivenessDetector**); this service focuses on **embedding extraction** and optional legacy verify paths.

### Data & infrastructure

| Component | Technology |
|-----------|------------|
| **Database** | **MySQL 8.x** (migrations in `backend/database/migrations`) |
| **Cache / queues** | Laravel cache, **database** or **Redis** queue drivers (see `config/queue.php`) |
| **File storage** | Laravel `storage/` + public media URLs for profiles, documents |
| **Cloud (optional)** | **AWS** Rekognition, Cognito (Amplify), S3 |

---

## Repository layout

```
HR/
├── backend/           # Laravel 12 API (Composer, artisan)
├── frontend/          # React 19 + Vite 7 SPA (npm)
├── face_service/      # Python FastAPI + DeepFace embedding service
├── README.md          # This file
└── (root)             # Optional workspace scripts if present
```

---

## Prerequisites

- **PHP** ≥ 8.2, **Composer**
- **Node.js** LTS (v20+) and **npm**
- **MySQL** 8.x
- **Python** 3.10+ and **pip** (for `face_service/`)

---

## Quick start (local)

### 1. Database

```sql
CREATE DATABASE hris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Backend

```bash
cd backend
cp .env.example .env
php artisan key:generate
```

Configure `.env` (`DB_*`, `APP_URL`, `QUEUE_CONNECTION`, AWS keys for Rekognition/face, mail/SMS, etc.), then:

```bash
composer install
php artisan migrate
php artisan serve
```

API base is typically `http://localhost:8000/api` (see `routes/api.php`).

### 3. Queue worker (payroll, face registration, mail)

```bash
cd backend
php artisan queue:work database --queue=face-registration,default --timeout=90
```

For concurrency bursts (e.g. 20+ employees clocking in with face recognition), do **not** open 20 terminals manually.
Run multiple worker processes under a supervisor (Linux) or process manager:

```bash
# Linux (Supervisor): use backend/deployment/supervisor-face-registration.conf.example
# Increase numprocs (e.g. 8, 20, 50) and reload supervisor.
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart laravel-worker-face-registration:*
```

Windows local quick scale example (PowerShell):

```powershell
1..8 | ForEach-Object { Start-Process php -ArgumentList "artisan queue:work database --queue=face-registration,default --timeout=150 --sleep=1 --tries=3" -WorkingDirectory ".\backend" }
```

Rule of thumb: you do **not** need one worker per employee. Start with 6-10 workers, monitor queue delay, then scale up if needed.

### 4. Frontend

```bash
cd frontend
npm install
# copy .env.example to .env if present — set VITE_API_URL to your API base
npm run dev
```

Default Vite dev URL: `http://localhost:5173`.

### 5. Face embedding service (optional local)

```bash
cd face_service
pip install -r requirements.txt
uvicorn main:app --host 0.0.0.0 --port 5000
```

Point Laravel `FACE_VERIFICATION_URL` (or equivalent in `config/services.php`) at this service.

---

## Authentication

The SPA uses **Laravel Sanctum** with **Bearer tokens** (`Authorization: Bearer <token>`). Configure token lifetime and CORS for your production origins in `.env` and `config/cors.php`.

---

## Security & production

- Do not commit `.env` or production secrets; use `.env.example` as a template only.
- Use HTTPS, restrict CORS, and run `php artisan config:cache` / `route:cache` in production.
- Rotate default seeded passwords before go-live.
- Back up MySQL and `storage/` according to your retention policy.

---

## License

Add a `LICENSE` file (e.g. MIT or proprietary). Until then, **all rights reserved** unless stated otherwise.

---

## Author

**KurtJMinoza** — [github.com/KurtJMinoza](https://github.com/KurtJMinoza)
