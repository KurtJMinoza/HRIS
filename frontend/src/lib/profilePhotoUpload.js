export const PROFILE_PHOTO_ACCEPT = 'image/jpeg,image/jpg,image/png,image/gif,image/webp'
export const PROFILE_PHOTO_MAX_MB = 2

const ALLOWED_PROFILE_PHOTO_TYPES = new Set([
  'image/jpeg',
  'image/jpg',
  'image/png',
  'image/gif',
  'image/webp',
])

/** GIF uploads skip the crop dialog so animation is preserved. */
export function shouldSkipProfilePhotoCrop(file) {
  return String(file?.type || '').toLowerCase() === 'image/gif'
}

export function validateProfilePhotoFile(file, maxMb = PROFILE_PHOTO_MAX_MB) {
  if (!file) return 'No file selected.'
  const type = String(file.type || '').toLowerCase()
  if (!ALLOWED_PROFILE_PHOTO_TYPES.has(type)) {
    return 'Use JPEG, PNG, GIF, or WebP.'
  }
  if (file.size > maxMb * 1024 * 1024) {
    return `Image must be under ${maxMb} MB.`
  }
  return null
}

export const PROFILE_PHOTO_HINT =
  'JPEG, PNG, GIF, or WebP up to 2 MB. Animated GIFs upload as-is (no crop).'
