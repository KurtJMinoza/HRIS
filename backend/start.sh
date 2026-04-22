 #!/bin/bash
echo "🚀 Starting Laravel HRIS..."

# Start the web server in background
php artisan serve --port=8000 &

# Start the queue worker for face registration in background
# --timeout must exceed the job's $timeout (120s) to give it room to finish before the worker kills it
echo "📋 Starting Face Registration Queue Worker..."
php artisan queue:work database --queue=face-registration,default --timeout=150 --sleep=3 --tries=3 --daemon &

echo "✅ Laravel HRIS is running!"
wait