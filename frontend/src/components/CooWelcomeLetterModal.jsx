import { useCallback, useEffect, useState } from 'react'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogTitle,
} from '@/components/ui/dialog'
import { dismissCooWelcomeLetter, getCooWelcomeLetterStatus } from '@/api'
import { COO_WELCOME_USER_ID } from '@/lib/cooWelcomeLetter'
import { scheduleRadixModalLockReset } from '@/lib/radixModalLock'
import { useAuth } from '@/contexts/AuthContext'

function LetterParagraph({ children }) {
  return <p className="text-[13px] leading-[1.65] text-foreground/90 sm:text-sm">{children}</p>
}

export function CooWelcomeLetterModal() {
  const { user, setUser } = useAuth()
  const [open, setOpen] = useState(false)
  const [closing, setClosing] = useState(false)

  useEffect(() => {
    if (!user?.id || Number(user.id) !== COO_WELCOME_USER_ID) {
      setOpen(false)
      return undefined
    }

    let cancelled = false

    getCooWelcomeLetterStatus()
      .then((data) => {
        if (cancelled) return
        const shouldShow = data?.show_coo_welcome_letter === true
        setOpen(shouldShow)
        if (user.show_coo_welcome_letter !== shouldShow) {
          setUser((prev) => (prev ? { ...prev, show_coo_welcome_letter: shouldShow } : prev))
        }
      })
      .catch(() => {
        if (!cancelled) setOpen(false)
      })

    return () => {
      cancelled = true
    }
  }, [user?.id, setUser])

  const handleClose = useCallback(async () => {
    if (closing) return
    setClosing(true)
    try {
      await dismissCooWelcomeLetter()
      setUser((prev) => (prev ? { ...prev, show_coo_welcome_letter: false } : prev))
      setOpen(false)
      scheduleRadixModalLockReset()
    } catch {
      // Keep modal open if the server did not record dismissal.
    } finally {
      setClosing(false)
    }
  }, [closing, setUser])

  if (!user?.id || Number(user.id) !== COO_WELCOME_USER_ID) return null

  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        if (nextOpen) setOpen(true)
      }}
    >
      <DialogContent
        showCloseButton={false}
        className="max-w-3xl"
        innerClassName="gap-0 overflow-y-auto px-6 pb-5 pt-6 sm:px-8 sm:pb-6 sm:pt-7"
        onInteractOutside={(event) => event.preventDefault()}
        onEscapeKeyDown={(event) => event.preventDefault()}
        aria-describedby={undefined}
      >
        <div className="mb-5 flex justify-start sm:mb-6">
          <AgcBrandLogo className="h-10 w-auto sm:h-11" variant="light" />
        </div>

        <DialogTitle className="mb-5 text-center text-sm font-bold uppercase tracking-wide text-foreground sm:mb-6 sm:text-base">
          A Warm Welcome to Our New Chief Operating Officer
        </DialogTitle>

        <div className="space-y-4 text-left">
          <LetterParagraph>
            <span className="font-medium">Dear Ms. Cortez,</span>
          </LetterParagraph>

          <LetterParagraph>
            It is with great pleasure and sincere enthusiasm that we welcome you to{' '}
            <strong>Amalgamated Group of Companies</strong> as our new{' '}
            <strong>Chief Operating Officer</strong>.
          </LetterParagraph>

          <LetterParagraph>
            Your arrival marks an important chapter in our organization. As you take on this
            significant leadership role, we welcome not only the experience and expertise you bring,
            but also the opportunity to move forward together toward a stronger, more united, and
            more purposeful organization.
          </LetterParagraph>

          <LetterParagraph>
            As <strong>COO</strong>, you will play a vital role in transforming our strategies into
            meaningful results—strengthening our operations, developing our people, building
            accountability, and ensuring that every part of the organization works together toward a
            common vision.
          </LetterParagraph>

          <LetterParagraph>
            We believe that great leadership is not only measured by the results we achieve, but also
            by the people we inspire, the culture we build, and the legacy we leave behind. We look
            forward to your leadership in creating an environment where people are encouraged to
            perform with <strong>Excellence</strong>, lead with <strong>Integrity</strong>, work
            with <strong>Teamwork</strong>, respond with a <strong>Sense of Urgency</strong>, and
            treat one another with <strong>Compassion</strong> and <strong>Nurtureship</strong>.
          </LetterParagraph>

          <LetterParagraph>
            We are committed to standing beside you as you begin this journey. We will support you in
            building capable teams, strengthening our organizational culture, developing our people,
            and creating an environment where every employee has the opportunity to contribute, grow,
            and succeed.
          </LetterParagraph>

          <LetterParagraph>
            We recognize that stepping into a new organization comes with both opportunities and
            challenges. Please know that you are joining a team that is ready to work with you, learn
            from you, and contribute to the vision that we will build together.
          </LetterParagraph>

          <LetterParagraph>
            <strong>Your success will be our shared success.</strong>
          </LetterParagraph>

          <LetterParagraph>
            May your journey with <strong>Amalgamated Group of Companies</strong> be filled with
            meaningful accomplishments, strong partnerships, and opportunities to make a lasting
            difference.
          </LetterParagraph>

          <LetterParagraph>
            Once again, welcome to the family, <strong>Ms. Rocelyn G. Cortez</strong>.
          </LetterParagraph>

          <LetterParagraph>
            We are honored to have you with us, and we look forward to the leadership, inspiration,
            and positive impact you will bring to our organization.
          </LetterParagraph>

          <LetterParagraph>
            <strong>Welcome aboard, and may we achieve greater things together.</strong>
          </LetterParagraph>

          <div className="space-y-1 pt-2">
            <LetterParagraph>With warmest regards,</LetterParagraph>
            <LetterParagraph>
              <strong>HUMAN RESOURCES DEPARTMENT</strong>
              <br />
              <strong>Amalgamated Group of Companies</strong>
            </LetterParagraph>
          </div>
        </div>

        <DialogFooter className="mt-6 border-t border-border/60 pt-4 sm:mt-7">
          <Button type="button" className="min-w-[7rem]" disabled={closing} onClick={handleClose}>
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
