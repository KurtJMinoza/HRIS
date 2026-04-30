 #!/bin/bash
echo "🚀 Starting Laravel HRIS..."

# Start the web server in background
php artisan serve --port=8000 &

# Start the queue worker for face registration in background
# --timeout must exceed the job's $timeout (120s) to give it room to finish before the worker kills it.
# Scale workers with QUEUE_WORKERS (example: QUEUE_WORKERS=8 ./start.sh).
QUEUE_WORKERS="${QUEUE_WORKERS:-1}"
echo "📋 Starting Face Registration Queue Workers (count: ${QUEUE_WORKERS})..."
for i in $(seq 1 "$QUEUE_WORKERS"); do
  php artisan queue:work database --queue=face-registration,default --timeout=150 --sleep=1 --tries=3 &
done

echo "✅ Laravel HRIS is running!"
wait