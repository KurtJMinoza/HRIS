# AGENTS.md

## Repo map
- `backend/` — Laravel 12 API. API routes are centralized in `backend/routes/api.php`; keep controllers thin, put domain logic in `backend/app/Services`, and slow work in `backend/app/Jobs`.
- `frontend/` — React 19 + Vite 7 SPA. `@` resolves to `frontend/src`. The centralized API client is `frontend/src/api.js` (~9,500 lines); add endpoints there instead of creating another client. Direct `fetch` is limited to file previews and an external Mapillary lookup.
- Authenticated pages mount from `frontend/src/routes/hrPanelChildRoutes.jsx` (HR/org-head panel) or `employeeChildRoutes.jsx` (employee panel), via `AuthenticatedRoutes.jsx`. `App.jsx` is for public flows. When adding a gated page, update the matching permission in `backend/config/rbac.php` and `backend/routes/api.php`.
- `face_service/` — optional FastAPI/ONNX embedding service. Liveness is Amazon Rekognition; this service only extracts/compares face embeddings.
- `prompt-master-main/` is an unrelated vendored prompt skill. `backend/tmp_*.php` are untracked ad-hoc diagnostics; leave them out of commits and don't treat them as app code.
- Payroll/evaluation domain rules are documented in `backend/docs/HOLIDAY_POLICY_ENGINE.md` and `backend/docs/PERFORMANCE_EVALUATION.md`.
- There are separate npm trees: root (PM2/backup scripts), `frontend/` (SPA), and `backend/` (Laravel asset build). Run frontend commands from `frontend/`.

## Commands (this Windows/XAMPP checkout)
- `php` is not on PATH and `npm` is blocked by PowerShell execution policy. Use `$env:PHP_BIN` if set (currently `C:\xampp2\php\php.exe`), otherwise that path directly; invoke npm as `npm.cmd`.
- Backend test: `cd backend; & $env:PHP_BIN artisan test tests/Unit/FooTest.php` (or `--filter=TestName`). Full suite: `& $env:PHP_BIN artisan test`. Do not pass `--no-interaction`; PHPUnit rejects it.
- Frontend: `cd frontend; npm.cmd run build` (the real gate, ~1 min), `npm.cmd run lint`, `npm.cmd run dev` (:5173, proxies `/api` and `/sanctum` to :8000). To lint only touched files: `npx.cmd eslint src/path/File.jsx`.
- Full stack: from repo root, `npm.cmd run pm2:start` (or `.\pm2.ps1 start ecosystem.config.cjs --update-env`). Uses project `PM2_HOME` (`.pm2/`) via `scripts/pm2.cjs` — do **not** use bare `npx pm2 restart all` (that hits the global daemon and may restart unrelated apps like ERP). Restart HR only: `npm.cmd run pm2:restart` or `npm.cmd run pm2 -- restart all`. PM2 reads `PHP_BIN` (set it to `C:\xampp2\php\php.exe` if missing). Starts Octane on :8000, Vite on :5173, five face services on :2000-:2004, queue workers, and the scheduler. **Redis** on `127.0.0.1:6379` is required for queues/cache; `pm2:start` / `pm2:restart` run `scripts/ensure-redis-windows.cjs` (auto-starts Laragon’s `redis-server` if the port is closed). Or start Redis from Laragon / set `REDIS_SERVER_BIN`.

## Testing gotchas
- `backend/phpunit.xml` forces SQLite `:memory:`; the runtime DB in `backend/.env` is MySQL `hr`. New migrations must stay SQLite-safe or the suite breaks.
- For DB-backed feature tests use `RefreshDatabase`, not `DatabaseTransactions`: the latter leaves the in-memory DB without schema and fails with `no such table` (currently `DeviceGeofenceValidationTest` and `EmployeeGeofenceAssignmentTest`); don't copy that pattern.
- `npm.cmd run lint` is not a clean gate: ~130 errors already exist in untouched files. Use `npm.cmd run build` as the frontend pass/fail check and lint only what you change.
- There is no checked-in frontend unit/e2e runner (no Vitest/Playwright config); don't assume one is available.

## Ops gotchas
- PM2 starts workers only for queues listed in `ecosystem.config.cjs` (`payroll`, `payslip-pdf`, `face-registration`, `attendance-corrections`, `emails`, plus scheduler). Add a worker there if you introduce a new queue.
- Payroll jobs bypass the default queue connection and push directly to Redis (`PayrollQueueService`), so Redis is required even if `QUEUE_CONNECTION` changes.
- Docs drift: README/`face_service` use ports 5000-5003 while PM2 and `backend/.env` use 2000-2004; `DESIGN.md` says 8100/5200 while the actual dev ports are 8000/5173. Trust `ecosystem.config.cjs`, `vite.config.js`, and `.env`.
- Follow `.cursor/rules/ponytail.mdc` when present: smallest root-cause diff, reuse existing helpers, no unrequested abstractions/dependencies, and mark deliberate shortcuts with a `ponytail:` comment.
