/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',
  content: ['./index.html', './src/**/*.{js,jsx,ts,tsx}'],
  theme: {
    extend: {
      /** Extra breakpoints for ultra-wide / 4K / Smart TV casting (mirroring often lands at 1080p or 4K). */
      screens: {
        '3xl': '1920px',
        '4xl': '2560px',
      },
      keyframes: {
        'face-scan-line': {
          '0%': { top: '0', opacity: '0.88' },
          '12%': { opacity: '1' },
          '50%': { opacity: '1' },
          '88%': { opacity: '1' },
          '100%': { top: 'calc(100% - 4px)', opacity: '0.88' },
        },
        'face-scan-glow-pulse': {
          '0%, 100%': { opacity: '0.65' },
          '50%': { opacity: '1' },
        },
        'liveness-verified-scale': {
          '0%': { opacity: '0', transform: 'scale(0.8)' },
          '50%': { opacity: '1', transform: 'scale(1.05)' },
          '100%': { opacity: '1', transform: 'scale(1)' },
        },
        'face-glow-pulse': {
          '0%, 100%': {
            boxShadow: '0 0 20px rgba(0,245,160,0.4), 0 0 40px rgba(0,245,160,0.2)',
          },
          '50%': {
            boxShadow: '0 0 30px rgba(0,245,160,0.6), 0 0 60px rgba(0,245,160,0.3)',
          },
        },
        'scanner-pulse-border': {
          '0%, 100%': { borderColor: 'rgba(20,184,166,0.25)', boxShadow: '0 0 0 0 rgba(20,184,166,0.06)' },
          '50%': { borderColor: 'rgba(20,184,166,0.45)', boxShadow: '0 0 16px 2px rgba(20,184,166,0.1)' },
        },
      },
      animation: {
        'face-scan-line': 'face-scan-line 2.8s cubic-bezier(0.33,0,0.2,1) infinite',
        'face-scan-glow-pulse': 'face-scan-glow-pulse 2.8s ease-in-out infinite',
        'liveness-verified-scale': 'liveness-verified-scale 0.5s ease-out forwards',
        'face-glow-pulse': 'face-glow-pulse 2s ease-in-out infinite',
        'scanner-pulse-border': 'scanner-pulse-border 2.5s ease-in-out infinite',
      },
    },
  },
  plugins: [],
}
