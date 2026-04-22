"""
Download InsightFace buffalo_l ONNX model files.

Files downloaded (~170 MB total):
  det_10g.onnx   — SCRFD-10G face detector with 5-point landmarks
  w600k_r50.onnx — ArcFace ResNet-50 recognition model (512-D embeddings)

Run once before starting the service:
    python download_models.py
"""
import hashlib
import os
import sys
import zipfile
from pathlib import Path

import requests

# ── Where to save the models ──────────────────────────────────────────────────
MODEL_DIR = Path(
    os.getenv(
        "INSIGHTFACE_MODEL_DIR",
        str(Path.home() / ".insightface" / "models" / "buffalo_l"),
    )
)

# ── Source: official InsightFace GitHub release ───────────────────────────────
BUFFALO_L_URL = (
    "https://github.com/deepinsight/insightface/releases/download/v0.7/buffalo_l.zip"
)

# Only these two files are needed from the zip
NEEDED_FILES = {"det_10g.onnx", "w600k_r50.onnx"}


def _download(url: str, dest: Path, chunk_size: int = 1 << 20) -> None:
    """Stream-download *url* to *dest* with a progress bar."""
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp = dest.with_suffix(".part")
    print(f"  Downloading {url}")
    print(f"  Saving to : {dest}")
    with requests.get(url, stream=True, timeout=120) as r:
        r.raise_for_status()
        total = int(r.headers.get("content-length", 0))
        done = 0
        with open(tmp, "wb") as f:
            for chunk in r.iter_content(chunk_size=chunk_size):
                f.write(chunk)
                done += len(chunk)
                if total:
                    pct = done * 100 // total
                    bar = "#" * (pct // 5) + "." * (20 - pct // 5)
                    print(f"\r  [{bar}] {pct:3d}%  {done//1048576:4d}/{total//1048576} MB", end="", flush=True)
    print()
    tmp.rename(dest)


def main() -> None:
    MODEL_DIR.mkdir(parents=True, exist_ok=True)

    missing = [f for f in NEEDED_FILES if not (MODEL_DIR / f).exists()]
    if not missing:
        print(f"[OK] All model files already present in {MODEL_DIR}")
        for f in sorted(NEEDED_FILES):
            size_mb = (MODEL_DIR / f).stat().st_size / 1_048_576
            print(f"     {f}  ({size_mb:.1f} MB)")
        return

    zip_path = MODEL_DIR / "buffalo_l.zip"
    if not zip_path.exists():
        print(f"\n[1/2] Downloading buffalo_l model pack (~170 MB) …")
        try:
            _download(BUFFALO_L_URL, zip_path)
        except Exception as exc:
            print(f"\n[ERROR] Download failed: {exc}")
            print("\nManual download:")
            print(f"  1. Open: {BUFFALO_L_URL}")
            print(f"  2. Save as: {zip_path}")
            print(f"  3. Re-run this script.")
            sys.exit(1)

    print(f"\n[2/2] Extracting ONNX files from {zip_path.name} …")
    with zipfile.ZipFile(zip_path, "r") as zf:
        entries = zf.namelist()
        for entry in entries:
            fname = Path(entry).name
            if fname in NEEDED_FILES:
                target = MODEL_DIR / fname
                if not target.exists():
                    print(f"  Extracting {fname} …")
                    data = zf.read(entry)
                    target.write_bytes(data)
                    print(f"  Saved: {target}  ({len(data)/1_048_576:.1f} MB)")
                else:
                    print(f"  [skip] {fname} already exists")

    zip_path.unlink(missing_ok=True)

    still_missing = [f for f in NEEDED_FILES if not (MODEL_DIR / f).exists()]
    if still_missing:
        print(f"\n[ERROR] These files were not found in the zip: {still_missing}")
        print("The zip may be corrupt or the file names changed. Check manually.")
        sys.exit(1)

    print(f"\n[OK] Models ready in {MODEL_DIR}")
    for f in sorted(NEEDED_FILES):
        size_mb = (MODEL_DIR / f).stat().st_size / 1_048_576
        print(f"    {f}  ({size_mb:.1f} MB)")


if __name__ == "__main__":
    main()
