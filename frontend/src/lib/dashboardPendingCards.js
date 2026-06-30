export const DASHBOARD_PENDING_PREVIEW_LIMIT = 3

export const DASHBOARD_PENDING_CARD_SHELL_CLASS =
  'admin-dashboard-pending-card admin-dashboard-card flex h-[440px] max-h-[440px] min-h-[440px] flex-col gap-0 overflow-hidden py-0 transition-[transform,box-shadow] duration-300 hover:-translate-y-px @xl:h-[460px] @xl:max-h-[460px] @xl:min-h-[460px]'

export const DASHBOARD_PENDING_CARD_HEADER_CLASS =
  'shrink-0 border-b border-border/40 px-4 pb-2.5 pt-3 @sm:px-5 @md:px-6'

/** h-0 + flex-1: flex child must not grow with content — scroll lives here */
export const DASHBOARD_PENDING_SCROLL_CONTENT_CLASS =
  'dashboard-pending-scroll h-0 min-h-0 flex-1 overflow-y-scroll overscroll-contain [scrollbar-gutter:stable] touch-pan-y px-4 pb-3 pt-2 pr-1 @sm:px-5 @sm:pr-2 @md:px-6'

export const DASHBOARD_PENDING_SCROLL_LIST_CLASS = 'flex flex-col gap-2'

export const DASHBOARD_PENDING_SCROLL_BODY_PROPS = {
  onClick: (e) => e.stopPropagation(),
  onPointerDown: (e) => e.stopPropagation(),
  onWheel: (e) => e.stopPropagation(),
}
