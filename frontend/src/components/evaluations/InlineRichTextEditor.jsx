/* eslint-disable react-refresh/only-export-components */
import { useCallback, useMemo } from 'react'
import { useEditor, EditorContent } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'
import LinkExtension from '@tiptap/extension-link'
import TextAlignExtension from '@tiptap/extension-text-align'
import TextStyleExtension from '@tiptap/extension-text-style'
import ColorExtension from '@tiptap/extension-color'
import PlaceholderExtension from '@tiptap/extension-placeholder'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import {
  Tooltip, TooltipContent, TooltipProvider, TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Bold, Italic, Underline as UnderlineIcon,
  Link, List, ListOrdered, AlignLeft, AlignCenter, AlignRight,
  Palette, Pilcrow, Heading1, Heading2,
} from 'lucide-react'

const MINI_FONT_SIZES = [
  { value: '12px', label: '12' },
  { value: '14px', label: '14' },
  { value: '16px', label: '16' },
  { value: '18px', label: '18' },
  { value: '20px', label: '20' },
]

function MiniToolbarButton({ icon: Icon, active, onClick, tooltip }) {
  return (
    <TooltipProvider delayDuration={500}>
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            className={cn(
              'h-6 w-6 rounded text-muted-foreground transition-colors',
              active ? 'bg-brand/10 text-brand' : 'hover:bg-muted hover:text-foreground',
            )}
            onClick={onClick}
          >
            <Icon className="size-3" />
          </Button>
        </TooltipTrigger>
        <TooltipContent side="top" className="text-[10px]">{tooltip}</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

function MiniToolbarSeparator() {
  return <div className="mx-0.5 h-4 w-px bg-border/50" />
}

export default function InlineRichTextEditor({
  content,
  onChange,
  placeholder = 'Type here...',
  minHeight = '4rem',
  maxHeight = '12rem',
  compact = false,     // ultra-compact mode — no toolbar, click-to-edit
  simple = false,      // simple mode — only basic formatting toolbar
  className,
}) {
  const extensions = useMemo(() => [
    StarterKit.configure({
      heading: { levels: [1, 2, 3] },
      bulletList: { keepMarks: true },
      orderedList: { keepMarks: true },
    }),
    Underline,
    LinkExtension.configure({ openOnClick: false }),
    TextAlignExtension.configure({ types: ['heading', 'paragraph'] }),
    TextStyleExtension,
    ColorExtension,
    PlaceholderExtension.configure({ placeholder }),
  ], [placeholder])

  const editor = useEditor({
    extensions,
    content: content || '',
    onUpdate: ({ editor: ed }) => {
      const html = ed.getHTML()
      onChange?.(html)
    },
    immediatelyRender: false,
  })

  const setLink = useCallback(() => {
    if (!editor) return
    const previousUrl = editor.getAttributes('link').href
    const url = window.prompt('Enter URL:', previousUrl)
    if (url === null) return
    if (url === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run()
      return
    }
    editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
  }, [editor])

  if (!editor) {
    return (
      <div
        className="flex items-center justify-center rounded-lg border border-border/70 bg-muted/20"
        style={{ minHeight }}
      >
        <p className="text-xs text-muted-foreground">Loading...</p>
      </div>
    )
  }

  // ─── Compact: just a click-to-edit inline area ───
  if (compact) {
    return (
      <div
        className={cn(
          'prose prose-sm max-w-none cursor-text rounded-lg border border-border/60 bg-white p-2 transition-colors hover:border-brand/40 dark:bg-slate-950',
          className,
        )}
        style={{ minHeight, maxHeight, overflowY: 'auto' }}
        onClick={() => editor.commands.focus()}
      >
        <EditorContent editor={editor} />
      </div>
    )
  }

  return (
    <div className={cn('overflow-hidden rounded-lg border border-border/60 bg-card', className)}>
      {/* Mini Toolbar */}
      {!simple && (
        <div className="flex flex-wrap items-center gap-0.5 border-b border-border/40 bg-muted/15 px-1.5 py-1">
          {/* Headings */}
          <MiniToolbarButton icon={Pilcrow} active={editor.isActive('paragraph')} onClick={() => editor.chain().focus().setParagraph().run()} tooltip="Paragraph" />
          <MiniToolbarButton icon={Heading1} active={editor.isActive('heading', { level: 1 })} onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()} tooltip="Heading 1" />
          <MiniToolbarButton icon={Heading2} active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()} tooltip="Heading 2" />
          <MiniToolbarSeparator />

          {/* Basic formatting */}
          <MiniToolbarButton icon={Bold} active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()} tooltip="Bold" />
          <MiniToolbarButton icon={Italic} active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()} tooltip="Italic" />
          <MiniToolbarButton icon={UnderlineIcon} active={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()} tooltip="Underline" />
          <MiniToolbarSeparator />

          {/* Alignment */}
          <MiniToolbarButton icon={AlignLeft} active={editor.isActive({ textAlign: 'left' })} onClick={() => editor.chain().focus().setTextAlign('left').run()} tooltip="Align Left" />
          <MiniToolbarButton icon={AlignCenter} active={editor.isActive({ textAlign: 'center' })} onClick={() => editor.chain().focus().setTextAlign('center').run()} tooltip="Center" />
          <MiniToolbarButton icon={AlignRight} active={editor.isActive({ textAlign: 'right' })} onClick={() => editor.chain().focus().setTextAlign('right').run()} tooltip="Align Right" />
          <MiniToolbarSeparator />

          {/* Lists */}
          <MiniToolbarButton icon={List} active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()} tooltip="Bullet List" />
          <MiniToolbarButton icon={ListOrdered} active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()} tooltip="Numbered List" />
          <MiniToolbarSeparator />

          {/* Link & Color */}
          <MiniToolbarButton icon={Link} active={editor.isActive('link')} onClick={setLink} tooltip="Link" />
          <TooltipProvider delayDuration={500}>
            <Tooltip>
              <TooltipTrigger asChild>
                <div className="relative">
                  <Button type="button" variant="ghost" size="icon-sm" className="h-6 w-6 rounded text-muted-foreground hover:bg-muted hover:text-foreground">
                    <Palette className="size-3" />
                  </Button>
                  <input
                    type="color"
                    value={editor.getAttributes('textStyle').color || '#000000'}
                    onChange={(e) => editor.chain().focus().setColor(e.target.value).run()}
                    className="absolute inset-0 cursor-pointer opacity-0"
                    title="Text color"
                  />
                </div>
              </TooltipTrigger>
              <TooltipContent side="top" className="text-[10px]">Text Color</TooltipContent>
            </Tooltip>
          </TooltipProvider>
        </div>
      )}

      {/* Editor Body */}
      <div
        className={cn(
          'prose prose-sm max-w-none overflow-y-auto bg-white p-2 dark:bg-slate-950',
        )}
        style={{ minHeight: simple ? minHeight : undefined, maxHeight, minHeight: simple ? minHeight : '3rem' }}
      >
        <EditorContent editor={editor} />
      </div>
    </div>
  )
}
