# Face Verification Service

FastAPI service for InsightFace ArcFace embedding extraction using ONNX Runtime.
Laravel calls this service after Amazon Rekognition Face Liveness succeeds.

- Liveness: Amazon Rekognition Face Liveness through Amplify UI.
- This service: extracts 512D face embeddings from the liveness reference image.
- Model lifecycle: models load once at service startup and stay warm in memory.

## Setup

1. Install dependencies:

   ```bash
   pip install -r requirements.txt
   python download_models.py
   ```

2. Run one warm service instance:

   ```bash
   uvicorn main:app --host 0.0.0.0 --port 5000
   ```

3. Check health:

   ```bash
   curl http://127.0.0.1:5000/health
   ```

## Concurrent Clock-In/Out

For several employees clocking in at the same time, run multiple warm Python
processes on different ports:

```bash
uvicorn main:app --host 127.0.0.1 --port 5000
uvicorn main:app --host 127.0.0.1 --port 5001
uvicorn main:app --host 127.0.0.1 --port 5002
uvicorn main:app --host 127.0.0.1 --port 5003
```

Then configure Laravel:

```env
FACE_VERIFICATION_URLS=http://127.0.0.1:5000,http://127.0.0.1:5001,http://127.0.0.1:5002,http://127.0.0.1:5003
```

Each process loads its own detector and recognizer, so start with 2 to 4
instances and increase only when CPU/RAM still have headroom.

Redis queues are still useful for face registration jobs. Real-time clock-in
and clock-out verification should stay synchronous so attendance decisions are
returned immediately.

## Endpoints

- `GET /health` - service status and model readiness
- `POST /embed` - body: `{"image_base64": "..."}` returns `{descriptor, message}`
- `POST /verify` - legacy compatibility endpoint; returns liveness-compatible shape
