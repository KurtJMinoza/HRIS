/**
 * Download face-api.js models into public/models/.
 * Run from frontend folder: node scripts/download-face-models.js
 * Requires Node 18+ (for fetch).
 */
import { mkdir, writeFile } from 'fs/promises'
import { dirname, join } from 'path'
import { fileURLToPath } from 'url'
import { Buffer } from 'node:buffer'
import { stdout, exit } from 'node:process'

const __dirname = dirname(fileURLToPath(import.meta.url))
const OUT_DIR = join(__dirname, '..', 'public', 'models')
const BASE = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js-models/master'

// Each model lives in its own folder; we save into public/models/ with flat names for face-api.js loadFromUri(base).
const FILES = [
  { url: 'tiny_face_detector/tiny_face_detector_model-weights_manifest.json', out: 'tiny_face_detector_model-weights_manifest.json' },
  { url: 'tiny_face_detector/tiny_face_detector_model-shard1', out: 'tiny_face_detector_model-shard1' },
  { url: 'face_landmark_68/face_landmark_68_model-weights_manifest.json', out: 'face_landmark_68_model-weights_manifest.json' },
  { url: 'face_landmark_68/face_landmark_68_model-shard1', out: 'face_landmark_68_model-shard1' },
  { url: 'face_recognition/face_recognition_model-weights_manifest.json', out: 'face_recognition_model-weights_manifest.json' },
  { url: 'face_recognition/face_recognition_model-shard1', out: 'face_recognition_model-shard1' },
  { url: 'face_recognition/face_recognition_model-shard2', out: 'face_recognition_model-shard2' },
]

async function main() {
  await mkdir(OUT_DIR, { recursive: true })
  for (const { url: urlPath, out: outName } of FILES) {
    const url = `${BASE}/${urlPath}`
    stdout.write(`Fetching ${outName}... `)
    try {
      const res = await fetch(url)
      if (!res.ok) throw new Error(`${res.status} ${res.statusText}`)
      const buf = await res.arrayBuffer()
      const outPath = join(OUT_DIR, outName)
      await writeFile(outPath, Buffer.from(buf))
      console.log('OK')
    } catch (e) {
      console.log('FAIL:', e.message)
      exit(1)
    }
  }
  console.log('Models saved to public/models/')
}

main()
