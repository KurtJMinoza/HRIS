/* eslint-disable react-refresh/only-export-components */
import { useCallback, useMemo } from 'react'
import { useEditor, EditorContent } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'
import LinkExtension from '@tiptap/extension-link'
import ImageExtension from '@tiptap/extension-image'
import TableExtension from '@tiptap/extension-table'
import TableCellExtension from '@tiptap/extension-table-cell'
import TableHeaderExtension from '@tiptap/extension-table-header'
import TableRowExtension from '@tiptap/extension-table-row'
import TaskListExtension from '@tiptap/extension-task-list'
import TaskItemExtension from '@tiptap/extension-task-item'
import TextAlignExtension from '@tiptap/extension-text-align'
import ColorExtension from '@tiptap/extension-color'
import HighlightExtension from '@tiptap/extension-highlight'
import FontFamilyExtension from '@tiptap/extension-font-family'
import TextStyleExtension from '@tiptap/extension-text-style'
import SubscriptExtension from '@tiptap/extension-subscript'
import SuperscriptExtension from '@tiptap/extension-superscript'
import PlaceholderExtension from '@tiptap/extension-placeholder'
import HorizontalRuleExtension from '@tiptap/extension-horizontal-rule'
import { Button } from '@/components/ui/button'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import {
  Tooltip, TooltipContent, TooltipProvider, TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import {
  Bold, Italic, Underline as UnderlineIcon, Strikethrough,
  List, ListOrdered, ListChecks, Quote, Code2,
  Undo2, Redo2, AlignLeft, AlignCenter, AlignRight, AlignJustify,
  Heading1, Heading2, Heading3, Pilcrow,
  Link, Image, Table2, Minus, Palette, Highlighter,
  Subscript as SubscriptIcon, Superscript as SuperscriptIcon,
  RemoveFormatting, Indent, Outdent,
} from 'lucide-react'

const FONT_FAMILIES = [
  { value: 'Inter', label: 'Inter' },
  { value: 'Arial', label: 'Arial' },
  { value: 'Calibri', label: 'Calibri' },
  { value: 'Times New Roman', label: 'Times New Roman' },
  { value: 'Georgia', label: 'Georgia' },
  { value: 'Courier New', label: 'Courier New' },
  { value: 'Tahoma', label: 'Tahoma' },
  { value: 'Verdana', label: 'Verdana' },
]

const FONT_SIZES = [
  { value: '10px', label: '10' },
  { value: '11px', label: '11' },
  { value: '12px', label: '12' },
  { value: '14px', label: '14' },
  { value: '16px', label: '16' },
  { value: '18px', label: '18' },
  { value: '20px', label: '20' },
  { value: '24px', label: '24' },
  { value: '28px', label: '28' },
  { value: '32px', label: '32' },
  { value: '36px', label: '36' },
  { value: '48px', label: '48' },
  { value: '72px', label: '72' },
]

const COLORS = [
  '#000000', '#434343', '#666666', '#999999', '#b7b7b7', '#cccccc', '#d9d9d9', '#efefef',
  '#980000', '#ff0000', '#ff9900', '#ffff00', '#00ff00', '#00ffff', '#4a86e8', '#0000ff',
  '#9900ff', '#ff00ff', '#e6b8af', '#f4cccc', '#fce5cd', '#fff2cc', '#d9ead3', '#d0e0e3',
  '#c9daf8', '#cfe2f3', '#d9d2e9', '#ead1dc',
  '#dd7e6b', '#ea9999', '#f9cb9c', '#ffe599', '#b6d7a8', '#a2c4c9', '#a4c2f4', '#9fc5e8',
  '#b4a7d6', '#d5a6bd',
  '#cc4125', '#e06666', '#f6b26b', '#ffd966', '#93c47d', '#76a5af', '#6d9eeb', '#6fa8dc',
  '#8e7cc3', '#c27ba0',
  '#a61c00', '#cc0000', '#e69138', '#f1c232', '#6aa84f', '#45818e', '#3c78d8', '#3d85c6',
  '#674ea7', '#a64d79',
  '#85200c', '#990000', '#b45f06', '#bf9000', '#38761d', '#134f5c', '#1155cc', '#0b5394',
  '#351c75', '#741b47',
  '#5b0f00', '#660000', '#783f04', '#7f6000', '#274e13', '#0c343d', '#1c4587', '#073763',
  '#20124d', '#4c1130',
]

const HIGHLIGHT_COLORS = [
  '#ffff00', '#00ff00', '#00ffff', '#ff9900', '#ff0000', '#ff00ff', '#0000ff', '#cccccc',
]

function ToolbarButton({ icon: Icon, active, onClick, tooltip, variant = 'ghost' }) {
  return (
    <TooltipProvider delayDuration={300}>
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            type="button"
            variant={variant}
            size="icon-sm"
            className={cn(
              'h-8 w-8 rounded-md text-muted-foreground transition-colors',
              active ? 'bg-brand/10 text-brand hover:bg-brand/15' : 'hover:bg-muted hover:text-foreground',
            )}
            onClick={onClick}
          >
            <Icon className="size-4" />
          </Button>
        </TooltipTrigger>
        <TooltipContent side="bottom" className="text-xs">{tooltip}</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

function ToolbarSeparator() {
  return <div className="mx-0.5 h-6 w-px bg-border/60" />
}

export default function EvaluationRichTextEditor({ content, onChange, placeholder, minHeight = '24rem', variables = [] }) {
  const extensions = useMemo(() => [
    StarterKit.configure({
      heading: { levels: [1, 2, 3, 4, 5, 6] },
      bulletList: { keepMarks: true, keepAttributes: false },
      orderedList: { keepMarks: true, keepAttributes: false },
      codeBlock: { languageClassPrefix: 'language-' },
      horizontalRule: false,
    }),
    Underline,
    LinkExtension.configure({ openOnClick: false }),
    ImageExtension.configure({ inline: true }),
    TableExtension.configure({ resizable: true }),
    TableCellExtension,
    TableHeaderExtension,
    TableRowExtension,
    TaskListExtension,
    TaskItemExtension.configure({ nested: true }),
    TextAlignExtension.configure({ types: ['heading', 'paragraph'] }),
    TextStyleExtension,
    ColorExtension,
    HighlightExtension.configure({ multicolor: true }),
    FontFamilyExtension,
    SubscriptExtension,
    SuperscriptExtension,
    HorizontalRuleExtension,
    PlaceholderExtension.configure({ placeholder: placeholder || 'Start typing...' }),
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

  const addImage = useCallback(() => {
    if (!editor) return
    const url = window.prompt('Enter image URL:')
    if (url) {
      editor.chain().focus().setImage({ src: url }).run()
    }
  }, [editor])

  const insertTable = useCallback(() => {
    if (!editor) return
    editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()
  }, [editor])

  const insertEmoji = useCallback((emoji) => {
    if (!editor) return
    editor.chain().focus().insertContent(emoji).run()
  }, [editor])

  const insertVariable = useCallback((token) => {
    if (!editor) return
    editor.chain().focus().insertContent(token).run()
  }, [editor])

  const addHorizontalRule = useCallback(() => {
    if (!editor) return
    editor.chain().focus().setHorizontalRule().run()
  }, [editor])

  if (!editor) {
    return (
      <div
        className="flex items-center justify-center rounded-lg border border-border/70 bg-muted/20"
        style={{ minHeight }}
      >
        <p className="text-sm text-muted-foreground">Loading editor...</p>
      </div>
    )
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border/70 bg-card">
      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-0.5 border-b border-border/60 bg-muted/20 p-1.5">
        {/* Undo/Redo */}
        <ToolbarButton icon={Undo2} onClick={() => editor.chain().focus().undo().run()} tooltip="Undo" />
        <ToolbarButton icon={Redo2} onClick={() => editor.chain().focus().redo().run()} tooltip="Redo" />
        <ToolbarSeparator />

        {/* Text Style */}
        <ToolbarButton icon={Bold} active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()} tooltip="Bold (Ctrl+B)" />
        <ToolbarButton icon={Italic} active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()} tooltip="Italic (Ctrl+I)" />
        <ToolbarButton icon={UnderlineIcon} active={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()} tooltip="Underline (Ctrl+U)" />
        <ToolbarButton icon={Strikethrough} active={editor.isActive('strike')} onClick={() => editor.chain().focus().toggleStrike().run()} tooltip="Strikethrough" />
        <ToolbarSeparator />

        {/* Headings */}
        <ToolbarButton icon={Pilcrow} active={editor.isActive('paragraph')} onClick={() => editor.chain().focus().setParagraph().run()} tooltip="Paragraph" />
        <ToolbarButton icon={Heading1} active={editor.isActive('heading', { level: 1 })} onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()} tooltip="Heading 1" />
        <ToolbarButton icon={Heading2} active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()} tooltip="Heading 2" />
        <ToolbarButton icon={Heading3} active={editor.isActive('heading', { level: 3 })} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()} tooltip="Heading 3" />
        <ToolbarSeparator />

        {/* Font Family */}
        <Select
          value={editor.getAttributes('textStyle').fontFamily || ''}
          onValueChange={(value) => editor.chain().focus().setFontFamily(value).run()}
        >
          <SelectTrigger className="mr-0.5 h-8 w-32 rounded-md text-xs border-border/60">
            <SelectValue placeholder="Font" />
          </SelectTrigger>
          <SelectContent>
            {FONT_FAMILIES.map(font => (
              <SelectItem key={font.value} value={font.value} style={{ fontFamily: font.value }}>{font.label}</SelectItem>
            ))}
          </SelectContent>
        </Select>

        {/* Font Size (inline style approach since @tiptap/extension-font-size is unavailable) */}
        <Select
          value={(editor.getAttributes('textStyle').fontSize || '').replace('px', '')}
          onValueChange={(value) => {
            editor.chain().focus().setMark('textStyle', { fontSize: `${value}px` }).run()
          }}
        >
          <SelectTrigger className="mr-0.5 h-8 w-20 rounded-md text-xs border-border/60">
            <SelectValue placeholder="Size" />
          </SelectTrigger>
          <SelectContent>
            {FONT_SIZES.map(fs => (
              <SelectItem key={fs.value} value={fs.value.replace('px', '')}>{fs.label}</SelectItem>
            ))}
          </SelectContent>
        </Select>
        <ToolbarSeparator />

        {/* Text Color */}
        <TooltipProvider delayDuration={300}>
          <Tooltip>
            <TooltipTrigger asChild>
              <div className="relative">
                <Button type="button" variant="ghost" size="icon-sm" className="h-8 w-8 rounded-md text-muted-foreground hover:bg-muted hover:text-foreground">
                  <Palette className="size-4" />
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
            <TooltipContent side="bottom" className="text-xs">Text Color</TooltipContent>
          </Tooltip>
        </TooltipProvider>

        {/* Highlight */}
        <TooltipProvider delayDuration={300}>
          <Tooltip>
            <TooltipTrigger asChild>
              <div className="relative">
                <Button type="button" variant="ghost" size="icon-sm" className="h-8 w-8 rounded-md text-muted-foreground hover:bg-muted hover:text-foreground">
                  <Highlighter className="size-4" />
                </Button>
                <input
                  type="color"
                  value={editor.getAttributes('highlight').color || '#ffff00'}
                  onChange={(e) => editor.chain().focus().toggleHighlight({ color: e.target.value }).run()}
                  className="absolute inset-0 cursor-pointer opacity-0"
                  title="Highlight color"
                />
              </div>
            </TooltipTrigger>
            <TooltipContent side="bottom" className="text-xs">Highlight</TooltipContent>
          </Tooltip>
        </TooltipProvider>
        <ToolbarSeparator />

        {/* Alignment */}
        <ToolbarButton icon={AlignLeft} active={editor.isActive({ textAlign: 'left' })} onClick={() => editor.chain().focus().setTextAlign('left').run()} tooltip="Align Left" />
        <ToolbarButton icon={AlignCenter} active={editor.isActive({ textAlign: 'center' })} onClick={() => editor.chain().focus().setTextAlign('center').run()} tooltip="Center" />
        <ToolbarButton icon={AlignRight} active={editor.isActive({ textAlign: 'right' })} onClick={() => editor.chain().focus().setTextAlign('right').run()} tooltip="Align Right" />
        <ToolbarButton icon={AlignJustify} active={editor.isActive({ textAlign: 'justify' })} onClick={() => editor.chain().focus().setTextAlign('justify').run()} tooltip="Justify" />
        <ToolbarSeparator />

        {/* Lists */}
        <ToolbarButton icon={List} active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()} tooltip="Bullet List" />
        <ToolbarButton icon={ListOrdered} active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()} tooltip="Numbered List" />
        <ToolbarButton icon={ListChecks} active={editor.isActive('taskList')} onClick={() => editor.chain().focus().toggleTaskList().run()} tooltip="Checklist" />
        <ToolbarSeparator />

        {/* Indent/Outdent */}
        <ToolbarButton icon={Indent} onClick={() => editor.chain().focus().sinkListItem('listItem').run()} tooltip="Increase Indent" />
        <ToolbarButton icon={Outdent} onClick={() => editor.chain().focus().liftListItem('listItem').run()} tooltip="Decrease Indent" />
        <ToolbarSeparator />

        {/* Blocks */}
        <ToolbarButton icon={Quote} active={editor.isActive('blockquote')} onClick={() => editor.chain().focus().toggleBlockquote().run()} tooltip="Block Quote" />
        <ToolbarButton icon={Code2} active={editor.isActive('codeBlock')} onClick={() => editor.chain().focus().toggleCodeBlock().run()} tooltip="Code Block" />
        <ToolbarButton icon={SubscriptIcon} active={editor.isActive('subscript')} onClick={() => editor.chain().focus().toggleSubscript().run()} tooltip="Subscript" />
        <ToolbarButton icon={SuperscriptIcon} active={editor.isActive('superscript')} onClick={() => editor.chain().focus().toggleSuperscript().run()} tooltip="Superscript" />
        <ToolbarSeparator />

        {/* Insert */}
        <ToolbarButton icon={Table2} onClick={insertTable} tooltip="Insert Table" />
        <ToolbarButton icon={Image} onClick={addImage} tooltip="Insert Image" />
        <ToolbarButton icon={Link} active={editor.isActive('link')} onClick={setLink} tooltip="Insert Link" />
        <ToolbarButton icon={Minus} onClick={addHorizontalRule} tooltip="Horizontal Rule" />
        <ToolbarSeparator />

        {/* Remove Formatting */}
        <ToolbarButton icon={RemoveFormatting} onClick={() => editor.chain().focus().clearNodes().unsetAllMarks().run()} tooltip="Clear Formatting" />
      </div>

      {/* Editor Content */}
      <div
        className="prose prose-sm max-w-none overflow-y-auto bg-white p-6 dark:bg-slate-950"
        style={{ minHeight }}
      >
        <EditorContent editor={editor} />
      </div>

      {/* Variables Bar */}
      {variables.length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5 border-t border-border/50 bg-muted/15 p-2">
          <span className="mr-1 text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Variables:</span>
          {variables.map(variable => (
            <button
              key={variable}
              type="button"
              onClick={() => insertVariable(variable)}
              className="rounded-md border border-border/60 bg-card px-2 py-0.5 font-mono text-[11px] font-semibold text-brand transition hover:border-brand/40 hover:bg-brand/10"
            >
              {variable}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
