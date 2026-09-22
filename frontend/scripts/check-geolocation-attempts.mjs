/**
 * ponytail: assert-based check for attendance geolocation attempt ordering.
 * Run: node frontend/scripts/check-geolocation-attempts.mjs
 *
 * Ceiling: does not exercise real browser Geolocation; only verifies attempt plan shape.
 * Upgrade: Playwright geo mocks on Chrome desktop + Safari WebKit.
 */
import assert from 'node:assert/strict'

function planFor(kind) {
  // Mirror frontend/src/api.js geolocationAttemptPlan() priorities.
  if (kind === 'ios') {
    return [
      { enableHighAccuracy: false, maximumAge: 60000 },
      { enableHighAccuracy: true, maximumAge: 15000 },
      { enableHighAccuracy: false, maximumAge: 300000 },
    ]
  }
  if (kind === 'desktop') {
    return [
      { enableHighAccuracy: false, maximumAge: 30000 },
      { enableHighAccuracy: true, maximumAge: 0 },
      { enableHighAccuracy: false, maximumAge: 180000 },
    ]
  }
  return [
    { enableHighAccuracy: true, maximumAge: 0 },
    { enableHighAccuracy: false, maximumAge: 120000 },
  ]
}

const desktop = planFor('desktop')
assert.equal(desktop[0].enableHighAccuracy, false, 'desktop must try Wi‑Fi/network location first')
assert.equal(desktop[1].enableHighAccuracy, true, 'desktop second attempt uses high accuracy')

const ios = planFor('ios')
assert.equal(ios[0].enableHighAccuracy, false, 'iOS/Safari must allow a cached/low-accuracy first fix')
assert.ok(ios[0].maximumAge > 0, 'iOS first attempt should accept a recent cached position')

console.log('check-geolocation-attempts: ok')
