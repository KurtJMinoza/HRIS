import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const filePath = path.resolve(__dirname, '..', 'frontend', 'src', 'api.js')

let content = fs.readFileSync(filePath, 'utf8')
let count = 0

// FIX 3: recordAttendanceKioskFace - skip prepareAttendanceLocation in kiosk mode
const kioskFaceMarker = 'const location = payload?.latitude == null && payload?.longitude == null\r\n    ? geolocationPayload((await prepareAttendanceLocation({\r\n      method: \'face\',\r\n      validate: false,\r\n      device_type: typeof payload === \'string\' ? undefined : payload?.device_type ?? payload?.deviceType,\r\n    })).location)\r\n    : geolocationPayload(payload)'

const kioskFaceReplacement = 'const location = payload?.latitude == null && payload?.longitude == null\r\n    // Kiosk face: skip frontend location capture \u2014 backend GeofenceValidationService handles two-level check\r\n    ? geolocationPayload({\r\n      device_type: typeof payload === \'string\' ? undefined : (payload?.device_type ?? payload?.deviceType ?? attendanceDeviceType()),\r\n    })\r\n    : geolocationPayload(payload)'

if (content.includes(kioskFaceMarker)) {
  content = content.replace(kioskFaceMarker, kioskFaceReplacement)
  count++
  console.log('FIX 3 applied: recordAttendanceKioskFace skips location capture')
} else {
  console.log('FIX 3 FAILED: marker not found in recordAttendanceKioskFace')
}

// FIX 4: recordAttendanceFace - wrap prepareAttendanceLocation with branchGeofencingEnabled check
const faceMarker = '  const location = payload?.latitude == null && payload?.longitude == null\r\n    ? geolocationPayload((await prepareAttendanceLocation({\r\n      method: \'face\',\r\n      validate: true,\r\n      employee_id: payload?.employee_id ?? payload?.employeeId,\r\n      branch_id: payload?.branch_id ?? payload?.branchId,\r\n      clock_type: type,\r\n      clicked_at: attempt.clicked_at,\r\n      device_type: payload?.device_type ?? payload?.deviceType,\r\n    })).location)\r\n    : geolocationPayload(payload)'

const faceReplacement = '  const location = payload?.latitude == null && payload?.longitude == null\r\n    ? geolocationPayload((await prepareAttendanceLocation({\r\n      method: \'face\',\r\n      validate: true,\r\n      employee_id: payload?.employee_id ?? payload?.employeeId,\r\n      branch_id: payload?.branch_id ?? payload?.branchId,\r\n      clock_type: type,\r\n      clicked_at: attempt.clicked_at,\r\n      device_type: payload?.device_type ?? payload?.deviceType,\r\n      branchGeofencingEnabled: payload?.branchGeofencingEnabled,\r\n    })).location)\r\n    : geolocationPayload(payload)'

if (content.includes(faceMarker)) {
  content = content.replace(faceMarker, faceReplacement)
  count++
  console.log('FIX 4 applied: recordAttendanceFace passes branchGeofencingEnabled')
} else {
  console.log('FIX 4 FAILED: marker not found in recordAttendanceFace')
}

fs.writeFileSync(filePath, content, 'utf8')
console.log(`\nTotal fixes applied: ${count}/2`)
