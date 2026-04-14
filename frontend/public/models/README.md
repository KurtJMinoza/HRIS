# Face recognition models (face-api.js)

The app tries to load models from a CDN first; if that fails, it uses the files in this folder.

**Quick setup (recommended):** from the `frontend` folder run:

```bash
npm run download-models
```

This downloads the required model files from [face-api.js-models](https://github.com/justadudewhohacks/face-api.js-models) into `public/models/`.

**Manual setup:** clone https://github.com/justadudewhohacks/face-api.js-models and copy the contents of its `models` folder into this `public/models` folder. You need:

- `tiny_face_detector_model-weights_manifest.json` + `tiny_face_detector_model-shard1`
- `face_landmark_68_model-weights_manifest.json` + `face_landmark_68_model-shard1`
- `face_recognition_model-weights_manifest.json` + `face_recognition_model-shard1`

Without these files (and if the CDN is unavailable), face capture and verification will show a loading error.
