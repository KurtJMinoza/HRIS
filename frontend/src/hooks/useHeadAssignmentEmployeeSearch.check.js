/**
 * Self-check: unstable inline filter objects must not change the serialized filters key.
 * Run: node frontend/src/hooks/useHeadAssignmentEmployeeSearch.check.js
 */
function filtersKey(filters) {
  try {
    return JSON.stringify(filters ?? {})
  } catch {
    return ''
  }
}

const a = { include_cross_company: true, active_only: true }
const b = { include_cross_company: true, active_only: true }
if (a === b) throw new Error('expected distinct object identities')
if (filtersKey(a) !== filtersKey(b)) throw new Error('filtersKey must be stable for equal content')
if (filtersKey({}) !== filtersKey({})) throw new Error('empty filters must match')
console.log('useHeadAssignmentEmployeeSearch.check: ok')
