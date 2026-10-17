import { AgcBrandLogo } from '@/components/AgcBrandLogo'

const bodyClass = 'text-[15px] leading-[1.75] text-foreground/90 sm:text-base'
const strongClass = 'font-bold text-foreground'

export function CooWelcomeLetterContent() {
  return (
    <article className="font-serif">
      <div className="mb-6 flex flex-col items-center text-center">
        <AgcBrandLogo variant="light" className="mx-auto h-14 max-w-[220px] sm:h-16" alt="Amalgamated Group of Companies" />
        <p className="mt-4 text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">Amalgamated Group of Companies</p>
      </div>

      <h2 className="animate-in fade-in slide-in-from-bottom-2 text-center text-lg font-bold uppercase leading-snug tracking-wide text-foreground duration-700 fill-mode-both sm:text-xl">
        A Warm Welcome to Our New Chief Operating Officer
      </h2>

      <p className={`${bodyClass} mt-6 font-semibold`}>Dear Ms. Cortez,</p>

      <div className="mt-5 space-y-4">
        <p className={`${bodyClass} welcome-letter-line`} style={{ animationDelay: '80ms' }}>
          It is with great pleasure and sincere enthusiasm that we welcome you to{' '}
          <strong className={strongClass}>Amalgamated Group of Companies</strong> as our new{' '}
          <strong className={strongClass}>Chief Operating Officer</strong>.
        </p>
        <p className={`${bodyClass} welcome-letter-line`} style={{ animationDelay: '140ms' }}>
          Your arrival marks an important chapter in our organization. As you take on this significant leadership role, we
          welcome not only the experience and expertise you bring, but also the opportunity to move forward together
          toward a stronger, more united, and more purposeful organization.
        </p>
        <p className={`${bodyClass} welcome-letter-line`} style={{ animationDelay: '200ms' }}>
          As COO, you will play a vital role in transforming our strategies into meaningful results—strengthening our
          operations, developing our people, building accountability, and ensuring that every part of the organization
          works together toward a common vision.
        </p>
        <p className={`${bodyClass} welcome-letter-line`} style={{ animationDelay: '260ms' }}>
          We believe that great leadership is not only measured by the results we achieve, but also by the people we
          inspire, the culture we build, and the legacy we leave behind. We look forward to your leadership in creating
          an environment where people are encouraged to perform with <strong className={strongClass}>Excellence</strong>,{' '}
          <strong className={strongClass}>lead with Integrity</strong>, <strong className={strongClass}>work with Teamwork</strong>,{' '}
          <strong className={strongClass}>respond with a Sense of Urgency</strong>, and{' '}
          <strong className={strongClass}>treat one another with Compassion and Nurtureship</strong>.
        </p>
        <p className={`${bodyClass} welcome-letter-line`} style={{ animationDelay: '320ms' }}>
          We are committed to standing beside you as you begin this journey. We will support you in building capable
          teams, strengthening our organizational culture, developing our people, and creating an environment where
          every employee has the opportunity to contribute, grow, and succeed.
        </p>
        <p className={`${bodyClass} welcome-letter-line`} style={{ animationDelay: '380ms' }}>
          We recognize that stepping into a new organization comes with both opportunities and challenges. Please know
          that you are joining a team that is ready to work with you, learn from you, and contribute to the vision that
          we will build together.
        </p>
        <p className={`${bodyClass} welcome-letter-line font-bold`} style={{ animationDelay: '440ms' }}>
          Your success will be our shared success.
        </p>
        <p className={`${bodyClass} welcome-letter-line`} style={{ animationDelay: '500ms' }}>
          May your journey with <strong className={strongClass}>Amalgamated Group of Companies</strong> be filled with
          meaningful accomplishments, strong partnerships, and opportunities to make a lasting difference.
        </p>
        <p className={`${bodyClass} welcome-letter-line`} style={{ animationDelay: '560ms' }}>
          Once again, welcome to the family, <strong className={strongClass}>Ms. Rocelyn G. Cortez</strong>.
        </p>
        <p className={`${bodyClass} welcome-letter-line`} style={{ animationDelay: '620ms' }}>
          We are honored to have you with us, and we look forward to the leadership, inspiration, and positive impact
          you will bring to our organization.
        </p>
        <p className={`${bodyClass} welcome-letter-line font-bold`} style={{ animationDelay: '680ms' }}>
          Welcome aboard, and may we achieve greater things together.
        </p>
      </div>

      <footer className="welcome-letter-line mt-8 border-t border-border/60 pt-6" style={{ animationDelay: '740ms' }}>
        <p className={`${bodyClass} mb-4`}>With warmest regards,</p>
        <p className="text-sm font-bold uppercase tracking-wide text-foreground">Human Resources Department</p>
        <p className="text-sm font-bold text-foreground">Amalgamated Group of Companies</p>
      </footer>
    </article>
  )
}
