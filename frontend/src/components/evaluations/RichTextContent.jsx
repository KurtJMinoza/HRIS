import { cn } from '@/lib/utils'

/**
 * RichTextContent
 *
 * A read-only component that renders HTML content produced by
 * TipTap / EvaluationRichTextEditor / InlineRichTextEditor with
 * proper Tailwind prose styling. Falls back gracefully when content
 * is empty or contains only plain text.
 *
 * Usage:
 *   <RichTextContent content={html} className="text-xs" />
 */
export default function RichTextContent({ content, className, fallback = '—' }) {
  if (!content || content === '<p></p>' || content.trim() === '') {
    return <span className={cn('text-muted-foreground/50 italic', className)}>{fallback}</span>
  }

  // Detect if content is just plain text (no HTML tags)
  const isPlainText = !/<\/?[a-z][\s\S]*>/i.test(content)

  if (isPlainText) {
    return <span className={className}>{content}</span>
  }

  return (
    <div
      className={cn(
        'prose prose-sm max-w-none',
        // Override prose to match muted/compact designs
        'prose-p:my-1 prose-p:leading-relaxed',
        'prose-headings:my-2 prose-headings:text-foreground',
        'prose-ul:my-1 prose-ol:my-1',
        'prose-li:my-0.5',
        'prose-a:text-brand prose-a:no-underline hover:prose-a:underline',
        'prose-strong:text-foreground',
        'prose-code:rounded prose-code:bg-muted prose-code:px-1 prose-code:py-0.5 prose-code:text-[0.9em]',
        'prose-pre:bg-muted prose-pre:border prose-pre:border-border/60',
        'prose-img:rounded-lg',
        'prose-table:text-sm',
        'prose-blockquote:border-l-brand/40 prose-blockquote:text-muted-foreground',
        'dark:prose-invert',
        className,
      )}
      dangerouslySetInnerHTML={{ __html: content }}
    />
  )
}

/**
 * stripHtml
 *
 * Strips HTML tags and decodes common entities — useful for
 * displaying plain-text snippets of rich text content
 * (e.g., in question previews or search results).
 */
export function stripHtml(html = '') {
  const doc = new DOMParser().parseFromString(html, 'text/html')
  return doc.body.textContent || ''
}
