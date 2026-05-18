#!/bin/bash
echo "Starting Laravel HRIS..."

# Start the web server in background
php artisan serve --port=8000 &

start_queue_workers() {
  local queue="$1"
  local timeout="$2"
  local count="$3"
  echo "Starting Redis queue '${queue}' workers (count: ${count})..."
  for i in $(seq 1 "$count"); do
    php artisan queue:work redis --queue="$queue" --timeout="$timeout" --sleep=1 --tries=2 &
  done
}

PAYROLL_QUEUE_WORKERS="${PAYROLL_QUEUE_WORKERS:-1}"
PAYSLIP_QUEUE_WORKERS="${PAYSLIP_QUEUE_WORKERS:-1}"
FACE_QUEUE_WORKERS="${FACE_QUEUE_WORKERS:-1}"
DEFAULT_QUEUE_WORKERS="${DEFAULT_QUEUE_WORKERS:-1}"

start_queue_workers "payroll" 300 "$PAYROLL_QUEUE_WORKERS"
start_queue_workers "payslip" 300 "$PAYSLIP_QUEUE_WORKERS"
start_queue_workers "face-registration" 180 "$FACE_QUEUE_WORKERS"
start_queue_workers "default" 120 "$DEFAULT_QUEUE_WORKERS"

echo "Laravel HRIS is running!"
wait
