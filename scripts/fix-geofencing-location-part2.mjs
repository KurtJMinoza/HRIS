import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const filePath = path.resolve(__dirname, '..', 'frontend', 'src', 'api.js')

let content = fs.readFileSync(filePath, 'utf8')
let count = 0

// FIX 4: recordAttendanceFace - pass branchGeofencingEnabled from payload
const faceMarker = `    ? geolocationPayload((await prepareAttendanceLocation({\n      method: 'face',\n      validate: true,\n      clock_type: type,\n      device_type: payload?.device_type ?? payload?.deviceType,\n      clicked_at: attempt.clicked_at,\n    })).location)\n  } else if (!location.geofence_validation_id) {`

const faceReplacement = `    ? geolocationPayload((await prepareAttendanceLocation({\n      method: 'face',\n      validate: true,\n      clock_type: type,\n      device_type: payload?.device_type ?? payload?.deviceType,\n      clicked_at: attempt.clicked_at,\n      branchGeofencingEnabled: payload?.branchGeofencingEnabled,\n    })).location)\n  } else if (!location.geofence_validation_id) {`

// Since the file has \r\n, also try without \r
const faceMarkerRN = `    ? geolocationPayload((await prepareAttendanceLocation({\r\n      method: 'face',\r\n      validate: true,\r\n      clock_type: type,\r\n      device_type: payload?.device_type ?? payload?.deviceType,\r\n      clicked_at: attempt.clicked_at,\r\n    })).location)\r\n  } else if (!location.geofence_validation_id) {`

const faceReplacementRN = `    ? geolocationPayload((await prepareAttendanceLocation({\r\n      method: 'face',\r\n      validate: true,\r\n      clock_type: type,\r\n      device_type: payload?.device_type ?? payload?.deviceType,\r\n      clicked_at: attempt.clicked_at,\r\n      branchGeofencingEnabled: payload?.branchGeofencingEnabled,\r\n    })).location)\r\n  } else if (!location.geofence_validation_id) {`

if (content.includes(faceMarkerRN)) {
  content = content.replace(faceMarkerRN, faceReplacementRN)
  count++
  console.log('FIX 4 applied: recordAttendanceFace passes branchGeofencingEnabled (\\r\\n)')
} else if (content.includes(faceMarker)) {
  content = content.replace(faceMarker, faceReplacement)
  count++
  console.log('FIX 4 applied: recordAttendanceFace passes branchGeofencingEnabled (\\n)')
} else {
  console.log('FIX 4 FAILED: marker not found')
  // Debug output
  const idx = content.indexOf("prepareAttendanceLocation")
  if (idx >= 0) console.log('Found prepareAttendanceLocation at', idx)
  
  // Find the specific one with validate: true
  const idx2 = content.indexOf("validate: true,")
  if (idx2 >= 0) console.log('Found validate: true at', idx2, 'context:', JSON.stringify(content.substring(idx2-20, idx2+200)))
}

fs.writeFileSync(filePath, content, 'utf8')
console.log(`\nTotal fixes applied: ${count}/1`)
