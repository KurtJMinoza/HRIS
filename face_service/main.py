"""
Face Verification FastAPI Service (embedding only).

Liveness is handled by Amazon Rekognition Face Liveness (Amplify UI FaceLivenessDetector).
This service only extracts 128D face embeddings via DeepFace Facenet for matching.
"""
import base64
from contextlib import asynccontextmanager

import cv2
import numpy as np
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

from deepface import DeepFace

# Tuning: small images are upscaled for detection; large images are downscaled for faster inference.
_MIN_EDGE_PX = 384
_MAX_EDGE_PX = 768
# Fast detectors first; RetinaFace last (heaviest). Rekognition crops are usually easy for opencv/ssd.
_DETECTORS = ["opencv", "ssd", "mediapipe", "mtcnn", "retinaface"]


def _warm_facenet_model() -> None:
    """Load Facenet weights at startup so the first /embed request is not a cold start."""
    try:
        if hasattr(DeepFace, "build_model"):
            DeepFace.build_model("Facenet")
    except Exception:
        # Service still works; first request may be slower.
        pass


@asynccontextmanager
async def _lifespan(_app: FastAPI):
    _warm_facenet_model()
    yield


app = FastAPI(title="Face Verification Service", version="2.0.0", lifespan=_lifespan)


class VerifyRequest(BaseModel):
    image_base64: str


class VerifyResponse(BaseModel):
    is_live: bool
    descriptor: list[float] | None
    message: str
    spoof_confidence: float | None = None


class EmbedResponse(BaseModel):
    descriptor: list[float] | None
    message: str


def _normalize_image_for_embedding(image: np.ndarray) -> np.ndarray:
    """Upscale small inputs; downscale very large inputs to reduce decode + inference time."""
    h, w = image.shape[:2]
    if w < _MIN_EDGE_PX or h < _MIN_EDGE_PX:
        scale = max(_MIN_EDGE_PX / w, _MIN_EDGE_PX / h)
        new_w, new_h = int(w * scale), int(h * scale)
        image = cv2.resize(image, (new_w, new_h), interpolation=cv2.INTER_LINEAR)
        h, w = image.shape[:2]
    if max(w, h) > _MAX_EDGE_PX:
        scale = _MAX_EDGE_PX / max(w, h)
        new_w, new_h = int(w * scale), int(h * scale)
        image = cv2.resize(image, (new_w, new_h), interpolation=cv2.INTER_AREA)
    return image


def extract_face_embedding(image: np.ndarray) -> list[float] | None:
    """Extract 128D face embedding using DeepFace Facenet. Tries multiple detectors for robustness."""
    image = _normalize_image_for_embedding(image)

    for backend in _DETECTORS:
        try:
            result = DeepFace.represent(
                img_path=image,
                model_name="Facenet",
                enforce_detection=True,
                detector_backend=backend,
            )
            if result and len(result) > 0:
                embedding = result[0].get("embedding")
                if embedding and len(embedding) == 128:
                    return [float(x) for x in embedding]
        except Exception:
            continue

    h, w = image.shape[:2]
    crop_frac = 0.65
    x1 = int(w * (1 - crop_frac) / 2)
    y1 = int(h * (1 - crop_frac) / 2)
    x2 = int(w * (1 + crop_frac) / 2)
    y2 = int(h * (1 + crop_frac) / 2)
    center_crop = image[y1:y2, x1:x2]
    if center_crop.size > 0:
        try:
            result = DeepFace.represent(
                img_path=center_crop,
                model_name="Facenet",
                enforce_detection=False,
            )
            if result and len(result) > 0:
                embedding = result[0].get("embedding")
                if embedding and len(embedding) == 128:
                    return [float(x) for x in embedding]
        except Exception:
            pass
    return None


def base64_to_image(b64: str) -> np.ndarray:
    """Decode base64 string to OpenCV image (BGR)."""
    data = base64.b64decode(b64)
    arr = np.frombuffer(data, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError("Invalid image data")
    return img


@app.get("/health")
def health():
    return {
        "status": "ok",
        "anti_spoof": False,
        "anti_spoof_backend": None,
        "liveness": "rekognition",
        "embed_tuning": {
            "min_edge_px": _MIN_EDGE_PX,
            "max_edge_px": _MAX_EDGE_PX,
            "detectors": _DETECTORS,
        },
    }


@app.post("/debug-dimensions")
def debug_dimensions(req: VerifyRequest):
    try:
        img = base64_to_image(req.image_base64)
        h, w = img.shape[:2]
        return {
            "width": w,
            "height": h,
            "channels": img.shape[2] if len(img.shape) > 2 else 1,
            "expected": "1280x720 for face registration",
        }
    except Exception as e:
        return {"error": str(e)}


@app.post("/embed", response_model=EmbedResponse)
def embed(req: VerifyRequest):
    """
    Extract 128D face descriptor only. No liveness check.
    Used with Amazon Rekognition Face Liveness (reference image from GetFaceLivenessSessionResults).
    """
    try:
        img = base64_to_image(req.image_base64)
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Invalid image: {e}")

    descriptor = extract_face_embedding(img)
    if descriptor is None:
        return EmbedResponse(
            descriptor=None,
            message="No face detected. Center your face in frame, ensure good lighting, and remove glasses if possible.",
        )
    return EmbedResponse(descriptor=descriptor, message="OK")


@app.post("/verify", response_model=VerifyResponse)
def verify(req: VerifyRequest):
    """
    Legacy: extract 128D descriptor only. Liveness is handled by Rekognition.
    Returns is_live=True and spoof_confidence=1.0 for backward compatibility.
    """
    try:
        img = base64_to_image(req.image_base64)
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Invalid image: {e}")

    descriptor = extract_face_embedding(img)
    if descriptor is None:
        return VerifyResponse(
            is_live=True,
            descriptor=None,
            message="No face detected. Center your face in frame, ensure good lighting, and remove glasses if possible.",
            spoof_confidence=1.0,
        )
    return VerifyResponse(
        is_live=True,
        descriptor=descriptor,
        message="OK",
        spoof_confidence=1.0,
    )
