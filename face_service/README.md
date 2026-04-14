# Face Verification Service

FastAPI service for **face embedding only** (DeepFace Facenet 128D). Used by the HR DTR after Amazon Rekognition Face Liveness.

- **Liveness**: Amazon Rekognition Face Liveness (Amplify UI FaceLivenessDetector). No local anti-spoof (MiniFASNet removed).
- **This service**: Extracts 128D descriptor from the reference image for face matching.

## Setup

1. Install dependencies:
   ```bash
   pip install -r requirements.txt
   ```

2. Run:
   ```bash
   uvicorn main:app --host 0.0.0.0 --port 5000
   ```

3. Check health: `GET /health` returns `liveness: "rekognition"`.

## Endpoints

- `GET /health` – Service status
- `POST /embed` – Body: `{"image_base64": "..."}` → `{descriptor, message}` (used with Rekognition reference image)
- `POST /verify` – Legacy: same as embed, returns `{is_live: true, descriptor, message, spoof_confidence: 1.0}`
