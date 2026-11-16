/** Rocelyn Gantilis Cortez — one-time COO welcome letter on login. */
export const COO_WELCOME_USER_ID = 1842

export function shouldShowCooWelcomeLetter(user) {
  if (!user?.id || Number(user.id) !== COO_WELCOME_USER_ID) return false
  return user.show_coo_welcome_letter === true
}
