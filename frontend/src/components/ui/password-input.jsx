import { forwardRef, useId, useState } from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

/**
 * Password field with show/hide toggle (accessible).
 */
export const PasswordInput = forwardRef(function PasswordInput(
  { className, id: idProp, 'aria-invalid': ariaInvalid, ...props },
  ref
) {
  const autoId = useId()
  const id = idProp ?? autoId
  const [show, setShow] = useState(false)

  return (
    <div className="relative">
      <Input
        id={id}
        ref={ref}
        type={show ? 'text' : 'password'}
        className={cn('pr-10', className)}
        aria-invalid={ariaInvalid}
        {...props}
      />
      <button
        type="button"
        tabIndex={-1}
        onClick={() => setShow((v) => !v)}
        className="absolute right-1.5 top-1/2 inline-flex size-8 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
        aria-label={show ? 'Hide password' : 'Show password'}
        aria-controls={id}
        aria-pressed={show}
      >
        {show ? <EyeOff className="size-4" aria-hidden /> : <Eye className="size-4" aria-hidden />}
      </button>
    </div>
  )
})
