"""
Face Verification FastAPI Service — InsightFace ArcFace via onnxruntime.

Does NOT require the `insightface` Python package (avoids Microsoft C++ Build
Tools on Windows).  Uses the same buffalo_l ONNX model files directly:
  • det_10g.onnx  — SCRFD-10G face detector  (5-point landmarks)
  • w600k_r50.onnx — ArcFace ResNet-50       (512-D L2-normalised embeddings)

First-time setup:
    pip install -r requirements.txt
    python download_models.py        ← downloads models (~170 MB, once only)
    uvicorn main:app --host 0.0.0.0 --port 5000

Liveness anti-spoofing is handled entirely by Amazon Rekognition Face Liveness
(Amplify UI FaceLivenessDetector). This service only extracts face embeddings.

Environment variables (all optional):
    INSIGHTFACE_MODEL_DIR   Directory containing the two .onnx files
                            Default: ~/.insightface/models/buffalo_l
    ONNX_PROVIDERS          Comma-separated ONNX Execution Providers
                            Default: CPUExecutionProvider
                            GPU: CUDAExecutionProvider,CPUExecutionProvider
"""
from __future__ import annotations

import base64
import os
from contextlib import asynccontextmanager
from pathlib import Path

import cv2
import numpy as np
import onnxruntime as ort
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

# ── Configuration ─────────────────────────────────────────────────────────────
_MODEL_DIR = Path(
    os.getenv(
        "INSIGHTFACE_MODEL_DIR",
        str(Path.home() / ".insightface" / "models" / "buffalo_l"),
    )
)
_ONNX_PROVIDERS: list[str] = [
    p.strip()
    for p in os.getenv("ONNX_PROVIDERS", "CPUExecutionProvider").split(",")
    if p.strip()
]

# ArcFace 5-point canonical landmarks for 112 × 112 face crop (from InsightFace)
_ARCFACE_DST = np.array(
    [
        [38.2946, 51.6963],
        [73.5318, 51.5014],
        [56.0252, 71.7366],
        [41.5493, 92.3655],
        [70.7299, 92.2041],
    ],
    dtype=np.float32,
)

_SCRFD_INPUT_SIZE = 640
_SCRFD_STRIDES = [8, 16, 32]
_SCRFD_NUM_ANCHORS = 2


# ── SCRFD Face Detector ────────────────────────────────────────────────────────
class _SCRFDDetector:
    """
    Wraps det_10g.onnx (SCRFD-10G) for face detection + 5-point landmark regression.
    Post-processing is a pure-NumPy/OpenCV re-implementation of InsightFace's scrfd.py.
    """

    def __init__(self, model_path: Path, providers: list[str]) -> None:
        self._sess = ort.InferenceSession(str(model_path), providers=providers)
        self._input_name = self._sess.get_inputs()[0].name
        # Build name→index map for robust output access
        self._out_idx = {o.name: i for i, o in enumerate(self._sess.get_outputs())}
        self._anchor_cache: dict[tuple, np.ndarray] = {}

    # -- anchor generation ------------------------------------------------
    def _anchor_centers(self, feat_h: int, feat_w: int, stride: int) -> np.ndarray:
        key = (feat_h, feat_w, stride)
        if key not in self._anchor_cache:
            cy, cx = np.mgrid[:feat_h, :feat_w]
            centers = np.stack(
                [cx.astype(np.float32), cy.astype(np.float32)], axis=-1
            ).reshape(-1, 2) * stride
            # Repeat for each anchor per location
            centers = np.tile(centers[:, np.newaxis, :], (1, _SCRFD_NUM_ANCHORS, 1))
            self._anchor_cache[key] = centers.reshape(-1, 2)
        return self._anchor_cache[key]

    # -- raw output access (by name then by positional fallback) ----------
    def _raw(self, all_outputs: list, name: str, fallback_idx: int) -> np.ndarray:
        idx = self._out_idx.get(name, fallback_idx)
        return all_outputs[idx]

    # -- main detection ---------------------------------------------------
    def detect(
        self,
        img_bgr: np.ndarray,
        score_thresh: float = 0.45,
        nms_iou_thresh: float = 0.4,
    ) -> tuple[np.ndarray | None, np.ndarray | None, np.ndarray | None]:
        """
        Returns (boxes, scores, landmarks) or (None, None, None) if no face.
        boxes     : float32 [N, 4]  — x1,y1,x2,y2 in original image pixels
        scores    : float32 [N]
        landmarks : float32 [N, 5, 2] — 5 key-points in original image pixels
        """
        ih, iw = img_bgr.shape[:2]
        scale = _SCRFD_INPUT_SIZE / max(ih, iw)
        nh, nw = int(ih * scale + 0.5), int(iw * scale + 0.5)

        # Letterbox resize to square
        padded = np.zeros((_SCRFD_INPUT_SIZE, _SCRFD_INPUT_SIZE, 3), dtype=np.uint8)
        padded[:nh, :nw] = cv2.resize(img_bgr, (nw, nh))

        # Preprocess: BGR→RGB, (x-127.5)/128, NCHW
        blob = cv2.dnn.blobFromImage(
            padded,
            scalefactor=1.0 / 128.0,
            size=(_SCRFD_INPUT_SIZE, _SCRFD_INPUT_SIZE),
            mean=(127.5, 127.5, 127.5),
            swapRB=True,
        )
        raw = self._sess.run(None, {self._input_name: blob})

        fmc = len(_SCRFD_STRIDES)
        all_boxes: list[np.ndarray] = []
        all_scores: list[np.ndarray] = []
        all_kps: list[np.ndarray] = []

        for i, stride in enumerate(_SCRFD_STRIDES):
            # Outputs: scores[0..fmc-1], bbox[fmc..2fmc-1], kps[2fmc..3fmc-1]
            score_out = self._raw(raw, f"score_{stride}", i).reshape(-1)
            bbox_out  = self._raw(raw, f"bbox_{stride}",  i + fmc).reshape(-1, 4) * stride
            kps_out   = self._raw(raw, f"kps_{stride}",   i + fmc * 2).reshape(-1, 5, 2) * stride

            feat_h = _SCRFD_INPUT_SIZE // stride
            feat_w = _SCRFD_INPUT_SIZE // stride
            centers = self._anchor_centers(feat_h, feat_w, stride)

            mask = score_out >= score_thresh
            if not np.any(mask):
                continue

            sc  = score_out[mask]
            bx  = bbox_out[mask]
            kp  = kps_out[mask]
            ct  = centers[mask]

            # Decode boxes (distance-from-center format) and scale to original
            x1 = (ct[:, 0] - bx[:, 0]) / scale
            y1 = (ct[:, 1] - bx[:, 1]) / scale
            x2 = (ct[:, 0] + bx[:, 2]) / scale
            y2 = (ct[:, 1] + bx[:, 3]) / scale
            boxes = np.stack([x1, y1, x2, y2], axis=1)

            # Decode landmarks and scale to original
            lm = kp.copy()
            lm[:, :, 0] = (ct[:, 0:1] + kp[:, :, 0]) / scale
            lm[:, :, 1] = (ct[:, 1:2] + kp[:, :, 1]) / scale

            all_boxes.append(boxes)
            all_scores.append(sc)
            all_kps.append(lm)

        if not all_boxes:
            return None, None, None

        boxes  = np.concatenate(all_boxes)
        scores = np.concatenate(all_scores)
        kps    = np.concatenate(all_kps)

        keep = _nms(boxes, scores, iou_thresh=nms_iou_thresh)
        return boxes[keep], scores[keep], kps[keep]


# ── ArcFace Recognizer ─────────────────────────────────────────────────────────
class _ArcFaceRecognizer:
    """
    Wraps w600k_r50.onnx for 512-D ArcFace embedding extraction.
    Input: aligned 112×112 RGB face image.
    Output: L2-normalised 512-D float32 vector.
    """

    def __init__(self, model_path: Path, providers: list[str]) -> None:
        self._sess = ort.InferenceSession(str(model_path), providers=providers)
        self._input_name = self._sess.get_inputs()[0].name

    def embed(self, face_rgb_112: np.ndarray) -> np.ndarray:
        # Normalise to [-1, 1], NCHW
        x = (face_rgb_112.astype(np.float32) / 127.5) - 1.0
        x = x.transpose(2, 0, 1)[np.newaxis]
        emb: np.ndarray = self._sess.run(None, {self._input_name: x})[0].reshape(-1)
        n = float(np.linalg.norm(emb))
        return emb / n if n > 1e-9 else emb


# ── Pure-NumPy / OpenCV helpers ────────────────────────────────────────────────

def _nms(boxes: np.ndarray, scores: np.ndarray, iou_thresh: float) -> list[int]:
    x1, y1, x2, y2 = boxes[:, 0], boxes[:, 1], boxes[:, 2], boxes[:, 3]
    areas = (x2 - x1 + 1.0) * (y2 - y1 + 1.0)
    order = scores.argsort()[::-1]
    keep: list[int] = []
    while order.size > 0:
        i = int(order[0])
        keep.append(i)
        if order.size == 1:
            break
        xx1 = np.maximum(x1[i], x1[order[1:]])
        yy1 = np.maximum(y1[i], y1[order[1:]])
        xx2 = np.minimum(x2[i], x2[order[1:]])
        yy2 = np.minimum(y2[i], y2[order[1:]])
        w = np.maximum(0.0, xx2 - xx1 + 1.0)
        h = np.maximum(0.0, yy2 - yy1 + 1.0)
        inter = w * h
        iou = inter / (areas[i] + areas[order[1:]] - inter)
        order = order[1:][iou <= iou_thresh]
    return keep


def _align_face(img_bgr: np.ndarray, landmarks5: np.ndarray) -> np.ndarray:
    """
    Affine-warp the detected face to a canonical 112×112 RGB crop
    using the 5-point ArcFace reference landmarks.
    """
    src = landmarks5.astype(np.float32)
    M, _ = cv2.estimateAffinePartial2D(src, _ARCFACE_DST, method=cv2.LMEDS)
    if M is None:
        # Fallback: full affine from first 3 points
        M = cv2.getAffineTransform(src[:3], _ARCFACE_DST[:3])
    aligned = cv2.warpAffine(img_bgr, M, (112, 112), borderValue=0)
    return cv2.cvtColor(aligned, cv2.COLOR_BGR2RGB)


def _base64_to_image(b64: str) -> np.ndarray:
    try:
        data = base64.b64decode(b64)
    except Exception as exc:
        raise ValueError(f"base64 decode failed: {exc}") from exc
    arr = np.frombuffer(data, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError("cv2 could not decode image (unsupported format or corrupt data)")
    return img


def _extract_embedding(img_bgr: np.ndarray) -> list[float] | None:
    """
    Full pipeline: detect → align → embed.
    Returns 512 floats (L2-normalised ArcFace embedding) or None if no face found.
    """
    boxes, scores, kps = _detector.detect(img_bgr)  # type: ignore[union-attr]
    if boxes is None or len(boxes) == 0:
        return None
    best = int(np.argmax(scores))
    face_rgb = _align_face(img_bgr, kps[best])
    emb = _recognizer.embed(face_rgb)  # type: ignore[union-attr]
    return [float(v) for v in emb]


# ── Global model instances (initialised at startup) ───────────────────────────
_detector: _SCRFDDetector | None = None
_recognizer: _ArcFaceRecognizer | None = None


@asynccontextmanager
async def _lifespan(_app: FastAPI):
    global _detector, _recognizer

    det_path = _MODEL_DIR / "det_10g.onnx"
    rec_path = _MODEL_DIR / "w600k_r50.onnx"

    if not det_path.exists() or not rec_path.exists():
        missing = [p for p in (det_path, rec_path) if not p.exists()]
        raise RuntimeError(
            f"Model file(s) missing: {[str(p) for p in missing]}\n"
            "Run:  python download_models.py"
        )

    print(f"[FaceService] Loading models from {_MODEL_DIR}  providers={_ONNX_PROVIDERS}")
    _detector   = _SCRFDDetector(det_path, _ONNX_PROVIDERS)
    _recognizer = _ArcFaceRecognizer(rec_path, _ONNX_PROVIDERS)
    print("[FaceService] Ready — SCRFD + ArcFace (512-D)")
    yield
    _detector = _recognizer = None


app = FastAPI(
    title="Face Verification Service (InsightFace ArcFace via onnxruntime)",
    version="3.1.0",
    lifespan=_lifespan,
)


# ── Pydantic schemas ──────────────────────────────────────────────────────────
class EmbedRequest(BaseModel):
    image_base64: str
    options: dict | None = None  # ignored — kept for backward compat


class VerifyRequest(BaseModel):
    image_base64: str
    options: dict | None = None  # ignored


class EmbedResponse(BaseModel):
    descriptor: list[float] | None
    message: str


class VerifyResponse(BaseModel):
    is_live: bool
    descriptor: list[float] | None
    message: str
    spoof_confidence: float | None = None


# ── Routes ────────────────────────────────────────────────────────────────────
@app.get("/health")
def health() -> dict:
    return {
        "status": "ok",
        "engine": "onnxruntime",
        "model": "buffalo_l (det_10g + w600k_r50)",
        "embedding_dim": 512,
        "anti_spoof": False,
        "liveness": "rekognition",
        "providers": _ONNX_PROVIDERS,
        "ready": _detector is not None and _recognizer is not None,
    }


@app.post("/embed", response_model=EmbedResponse)
def embed(req: EmbedRequest) -> EmbedResponse:
    """
    Extract a 512-D ArcFace embedding.  No liveness check.
    Called by Laravel after Rekognition Face Liveness passes and provides
    the reference image from GetFaceLivenessSessionResults.
    """
    try:
        img = _base64_to_image(req.image_base64)
    except Exception as exc:
        raise HTTPException(status_code=400, detail=f"Invalid image: {exc}")

    descriptor = _extract_embedding(img)
    if descriptor is None:
        return EmbedResponse(
            descriptor=None,
            message=(
                "No face detected. Center your face in the frame, "
                "ensure good lighting, and remove glasses if possible."
            ),
        )
    return EmbedResponse(descriptor=descriptor, message="OK")


@app.post("/verify", response_model=VerifyResponse)
def verify(req: VerifyRequest) -> VerifyResponse:
    """
    Legacy endpoint — extract 512-D descriptor only.
    Liveness is handled by Rekognition; returns is_live=True for compat.
    """
    try:
        img = _base64_to_image(req.image_base64)
    except Exception as exc:
        raise HTTPException(status_code=400, detail=f"Invalid image: {exc}")

    descriptor = _extract_embedding(img)
    if descriptor is None:
        return VerifyResponse(
            is_live=True,
            descriptor=None,
            message=(
                "No face detected. Center your face in the frame, "
                "ensure good lighting, and remove glasses if possible."
            ),
            spoof_confidence=1.0,
        )
    return VerifyResponse(
        is_live=True,
        descriptor=descriptor,
        message="OK",
        spoof_confidence=1.0,
    )
