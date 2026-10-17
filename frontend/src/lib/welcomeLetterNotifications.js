export const WELCOME_LETTER_NOTIFICATION_TYPE = 'welcome.coo_letter'

export function welcomeLetterKeyFromNotification(notification) {
  if (!notification) return null
  const dataKey = notification?.data?.letter_key
  if (typeof dataKey === 'string' && dataKey.trim()) return dataKey.trim()
  const type = notification?.type
  if (type === WELCOME_LETTER_NOTIFICATION_TYPE) return 'coo_rocelyn_cortez'
  return null
}

export function isWelcomeLetterNotification(notification) {
  return Boolean(welcomeLetterKeyFromNotification(notification))
}
