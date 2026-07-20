#!/bin/bash
echo "Starting Laravel HRIS..."

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$SCRIPT_DIR" || exit 1

start_face_services() {
  local ports_csv="${FACE_SERVICE_PORTS:-5000,5001,5002,5003}"
  local python_bin="${FACE_SERVICE_PYTHON:-python}"
  local face_dir="$REPO_DIR/face_service"
  local urls=()

  if [ ! -d "$face_dir" ]; then
    echo "face_service directory not found; skipping Python face services."
    return
  fi

  IFS=',' read -ra ports <<< "$ports_csv"
  echo "Starting Python face services on ports ${ports_csv}..."
  for port in "${ports[@]}"; do
    port="${port//[[:space:]]/}"
    [ -z "$port" ] && continue
    urls+=("http://127.0.0.1:${port}")
    (cd "$face_dir" && "$python_bin" -m uvicorn main:app --host 127.0.0.1 --port "$port") &
  done

  if [ -z "${FACE_VERIFICATION_URLS:-}" ]; then
    FACE_VERIFICATION_URLS="$(IFS=,; echo "${urls[*]}")"
    export FACE_VERIFICATION_URLS
  fi
}

if [ "${START_FACE_SERVICE:-false}" = "true" ]; then
  start_face_services
fi

# Start the web server in background
php artisan serve --port=8000 &

start_queue_workers() {
  local queue="$1"
  local timeout="$2"
  local count="$3"
  local tries="${4:-1}"
  echo "Starting Redis queue '${queue}' workers (count: ${count}, tries: ${tries})..."
  for i in $(seq 1 "$count"); do
    php artisan queue:work redis --queue="$queue" --timeout="$timeout" --sleep=1 --tries="$tries" &
  done
}

PAYROLL_QUEUE_WORKERS="${PAYROLL_QUEUE_WORKERS:-1}"
PAYSLIP_QUEUE_WORKERS="${PAYSLIP_QUEUE_WORKERS:-1}"
FACE_QUEUE_WORKERS="${FACE_QUEUE_WORKERS:-4}"
DEFAULT_QUEUE_WORKERS="${DEFAULT_QUEUE_WORKERS:-1}"

start_queue_workers "payroll" 300 "$PAYROLL_QUEUE_WORKERS"
start_queue_workers "payslip-pdf" 300 "$PAYSLIP_QUEUE_WORKERS"
start_queue_workers "face-registration" 180 "$FACE_QUEUE_WORKERS" 2
start_queue_workers "default" 120 "$DEFAULT_QUEUE_WORKERS"

echo "Laravel HRIS is running!"
wait
