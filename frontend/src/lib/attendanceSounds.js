/**
 * Optional sound feedback for attendance (success / error).
 * Uses Web Audio API so no asset files are required.
 */

let audioContext = null

function getContext() {
  if (typeof window === 'undefined') return null
  if (!audioContext) {
    try {
      audioContext = new (window.AudioContext || window.webkitAudioContext)()
    } catch {
      return null
    }
  }
  return audioContext
}

/**
 * Play a short success tone (two ascending notes).
 * @param {boolean} [enabled=true]
 */
export function playSuccess(enabled = true) {
  if (!enabled) return
  const ctx = getContext()
  if (!ctx) return
  try {
    const play = (freq, start, duration) => {
      const osc = ctx.createOscillator()
      const gain = ctx.createGain()
      osc.connect(gain)
      gain.connect(ctx.destination)
      osc.frequency.value = freq
      osc.type = 'sine'
      gain.gain.setValueAtTime(0.15, start)
      gain.gain.exponentialRampToValueAtTime(0.01, start + duration)
      osc.start(start)
      osc.stop(start + duration)
    }
    play(523.25, 0, 0.12)
    play(659.25, 0.14, 0.2)
  } catch {
    // ignore
  }
}

/**
 * Play a short error tone (low buzz).
 * @param {boolean} [enabled=true]
 */
export function playError(enabled = true) {
  if (!enabled) return
  const ctx = getContext()
  if (!ctx) return
  try {
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.connect(gain)
    gain.connect(ctx.destination)
    osc.frequency.value = 220
    osc.type = 'sawtooth'
    gain.gain.setValueAtTime(0.08, 0)
    gain.gain.exponentialRampToValueAtTime(0.01, 0.25)
    osc.start(0)
    osc.stop(0.25)
  } catch {
    // ignore
  }
}
