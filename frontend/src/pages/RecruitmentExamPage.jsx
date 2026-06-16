import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { AlertCircle, AlertTriangle, CheckCircle2, ChevronLeft, ChevronRight, Clock, Flag, Send } from 'lucide-react'
import { getPublicRecruitmentExam, submitPublicRecruitmentExam } from '@/api'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

const OPTION_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('')

function answerValueExists(value) {
  return Array.isArray(value) ? value.length > 0 : value != null && value !== ''
}

function normalizeQuestionType(type) {
  return String(type || '').trim().toLowerCase()
}

function questionTypeLabel(type) {
  const normalized = normalizeQuestionType(type)
  if (normalized === 'multiple choice') return 'Multiple Choice'
  if (normalized === 'true / false' || normalized === 'true/false') return 'True / False'
  if (normalized === 'checkbox') return 'Checkbox'
  if (normalized === 'file upload') return 'File Upload'
  if (normalized === 'identification') return 'Identification'
  if (normalized === 'short answer') return 'Short Answer'
  return type || 'Essay'
}

function applicantInitials(name) {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean)

  if (parts.length === 0) return 'AP'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase()
}

export default function RecruitmentExamPage() {
  const { token } = useParams()
  const [exam, setExam] = useState(null)
  const [answers, setAnswers] = useState({})
  const [files, setFiles] = useState({})
  const [currentIndex, setCurrentIndex] = useState(0)
  const [flagged, setFlagged] = useState({})
  const [error, setError] = useState('')
  const [submitted, setSubmitted] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [remainingSeconds, setRemainingSeconds] = useState(null)
  const autoSubmittedRef = useRef(false)

  useEffect(() => {
    let mounted = true
    getPublicRecruitmentExam(token)
      .then((data) => {
        if (!mounted) return
        setExam(data.exam)
        const startedAt = data.exam?.started_at ? new Date(data.exam.started_at).getTime() : Date.now()
        const totalSeconds = Number(data.exam?.duration_minutes || 0) * 60
        setRemainingSeconds(Math.max(0, Math.floor((startedAt + totalSeconds * 1000 - Date.now()) / 1000)))
      })
      .catch((err) => {
        if (mounted) setError(err.message || 'Failed to load exam')
      })
    return () => { mounted = false }
  }, [token])

  async function submit() {
    if (submitted || isSubmitting) return
    setError('')
    setIsSubmitting(true)
    try {
      const payload = (exam?.questions || []).map((question) => ({
        question_id: question.id,
        answer: answers[question.id] ?? '',
        file: files[question.id] ?? null,
      }))
      await submitPublicRecruitmentExam(token, payload)
      setSubmitted(true)
    } catch (err) {
      setError(err.message || 'Failed to submit exam')
    } finally {
      setIsSubmitting(false)
    }
  }

  useEffect(() => {
    if (remainingSeconds == null || submitted) return undefined
    const id = setInterval(() => {
      setRemainingSeconds((seconds) => {
        if (seconds == null) return seconds
        if (seconds <= 1 && !autoSubmittedRef.current) {
          autoSubmittedRef.current = true
          window.setTimeout(() => submit(), 0)
        }
        return Math.max(0, seconds - 1)
      })
    }, 1000)
    return () => clearInterval(id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [remainingSeconds, submitted])

  const questions = exam?.questions || []
  const currentQuestion = questions[currentIndex] || null
  const answeredCount = questions.filter((question) => answerValueExists(answers[question.id]) || Boolean(files[question.id])).length
  const notAnsweredCount = Math.max(0, questions.length - answeredCount)
  const progress = questions.length ? Math.round((answeredCount / questions.length) * 100) : 0

  const timerLabel = useMemo(() => {
    if (remainingSeconds == null) return '--:--:--'
    const hours = Math.floor(remainingSeconds / 3600)
    const minutes = Math.floor((remainingSeconds % 3600) / 60)
    const seconds = remainingSeconds % 60
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
  }, [remainingSeconds])

  const applicantInfo = useMemo(() => ([
    ['Position Applied', exam?.position_applied || '-'],
    ['Department', exam?.department || '-'],
    ['Company', exam?.company || '-'],
    ['Branch', exam?.branch || '-'],
    ['Assessment Status', exam?.status || 'In Progress'],
    ['Attempt', `Attempt ${exam?.attempt_number || 1} of ${exam?.max_attempts || 1}`],
    ['Exam', exam?.title || '-'],
  ]), [exam])

  if (!exam && error) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-[#f4f6fb] px-4 text-slate-950">
        <div className="max-w-md rounded-[22px] border border-red-100 bg-white p-8 text-center shadow-[0_20px_55px_-35px_rgba(15,23,42,0.6)]">
          <AlertCircle className="mx-auto size-11 text-red-500" />
          <h1 className="mt-4 text-lg font-black">Assessment unavailable</h1>
          <p className="mt-2 text-sm leading-6 text-slate-500">{error}</p>
        </div>
      </div>
    )
  }

  if (!exam) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-[#f4f6fb] px-4 text-slate-950">
        <div className="rounded-[22px] border border-slate-200 bg-white px-8 py-6 text-center text-sm font-semibold text-slate-500 shadow-sm">
          Loading assessment...
        </div>
      </div>
    )
  }

  if (submitted) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-[#f4f6fb] px-4 text-slate-950">
        <div className="max-w-md rounded-[26px] border border-emerald-100 bg-white p-8 text-center shadow-[0_24px_70px_-42px_rgba(15,23,42,0.65)]">
          <CheckCircle2 className="mx-auto size-12 text-emerald-600" />
          <h1 className="mt-4 text-xl font-extrabold text-slate-950">Assessment submitted</h1>
          <p className="mt-2 text-sm text-slate-500">Your answers were submitted to AGCTek Recruitment. HR will review your result.</p>
        </div>
      </div>
    )
  }

  function updateAnswer(questionId, value) {
    setAnswers((state) => ({ ...state, [questionId]: value }))
  }

  function toggleCheckbox(questionId, choice) {
    setAnswers((state) => {
      const current = Array.isArray(state[questionId]) ? state[questionId] : []
      return {
        ...state,
        [questionId]: current.includes(choice) ? current.filter((item) => item !== choice) : [...current, choice],
      }
    })
  }

  return (
    <div className="min-h-screen bg-[#f4f6fb] text-[#111827]">
      <header className="border-b border-slate-200/80 bg-white px-5 py-3 shadow-[0_1px_0_rgba(15,23,42,0.04)]">
        <div className="mx-auto flex max-w-[1260px] flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex min-w-0 items-center gap-7">
            <div className="shrink-0 leading-none">
              <div className="text-[1.55rem] font-black tracking-[-0.06em] text-slate-950">
                AGC<span className="text-[#f36a21]">TEK</span>
              </div>
              <div className="mt-1 text-[0.42rem] font-extrabold uppercase tracking-[0.13em] text-slate-500">
                People · Technology · Innovation
              </div>
            </div>
            <div className="min-w-0">
              <p className="text-[0.56rem] font-black uppercase tracking-[0.13em] text-[#f36a21]">Recruitment Assessment Portal</p>
              <h1 className="mt-0.5 truncate text-lg font-black uppercase tracking-[-0.02em] text-slate-950">{exam.title}</h1>
            </div>
          </div>

          <div className="grid grid-cols-3 gap-3 sm:min-w-[380px]">
            <div className="rounded-lg border border-slate-100 bg-[#f7f8fd] px-4 py-2 shadow-[0_8px_22px_-18px_rgba(15,23,42,0.45)]">
              <p className="text-[0.56rem] font-black uppercase tracking-wide text-slate-400">Question</p>
              <p className="mt-1 text-sm font-black">{Math.min(currentIndex + 1, questions.length)} of {questions.length}</p>
            </div>
            <div className="rounded-lg border border-orange-100 bg-[#fff6ee] px-4 py-2 text-[#ea580c] shadow-[0_8px_22px_-18px_rgba(234,88,12,0.55)]">
              <p className="text-[0.56rem] font-black uppercase tracking-wide">Time Remaining</p>
              <p className="mt-1 flex items-center gap-1.5 text-sm font-black tabular-nums"><Clock className="size-3.5" /> {timerLabel}</p>
            </div>
            <div className="rounded-lg border border-slate-100 bg-[#f7f8fd] px-4 py-2 shadow-[0_8px_22px_-18px_rgba(15,23,42,0.45)]">
              <p className="text-[0.56rem] font-black uppercase tracking-wide text-slate-400">Progress</p>
              <div className="mt-2 flex items-center gap-3">
                <div className="h-2 flex-1 rounded-full bg-slate-300/80">
                  <div className="h-2 rounded-full bg-slate-500 transition-all" style={{ width: `${progress}%` }} />
                </div>
                <span className="text-xs font-black text-slate-700">{progress}%</span>
              </div>
            </div>
          </div>
        </div>
      </header>

      <main className="mx-auto grid max-w-[1260px] gap-4 px-4 py-5 lg:grid-cols-[240px_minmax(0,1fr)] xl:grid-cols-[240px_minmax(0,1fr)_280px]">
        <aside className="rounded-[10px] border border-slate-200 bg-white p-4 shadow-[0_16px_42px_-32px_rgba(15,23,42,0.55)]">
          <h2 className="text-sm font-black">Applicant Information</h2>

          <div className="mt-5 flex items-center gap-3">
            <div className="flex size-14 shrink-0 items-center justify-center rounded-full bg-[#ffe7d6] text-lg font-black text-[#f36a21]">
              {applicantInitials(exam.applicant_name)}
            </div>
            <div className="min-w-0">
              <p className="truncate text-sm font-black text-slate-950">{exam.applicant_name || 'Applicant'}</p>
              <p className="text-[0.7rem] font-semibold text-slate-500">{exam.position_applied || '-'}</p>
              <p className="mt-0.5 text-[0.65rem] font-bold text-slate-500">{exam.applicant_no || `APP-${exam.assignment_id}`}</p>
            </div>
          </div>

          <div className="mt-5 space-y-3">
            {applicantInfo.map(([label, value]) => (
              <div key={label} className="rounded-lg bg-[#f8fafc] px-3 py-2.5">
                <p className="text-[0.58rem] font-black uppercase tracking-wide text-slate-400">{label}</p>
                <p className={cn('mt-1 text-[0.72rem] font-black leading-5 text-slate-900', label === 'Assessment Status' && 'text-[#f36a21]')}>
                  {value}
                </p>
              </div>
            ))}
          </div>

          {exam.instructions ? (
            <div className="mt-4 rounded-lg border border-orange-100 bg-orange-50/80 p-3 text-[0.68rem] font-medium leading-5 text-orange-800">
              {exam.instructions}
            </div>
          ) : null}
        </aside>

        <section className="rounded-[10px] border border-slate-200 bg-white shadow-[0_16px_42px_-32px_rgba(15,23,42,0.55)]">
          {currentQuestion ? (
            <>
              <div className="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-5">
                <div>
                  <p className="text-[0.55rem] font-black uppercase tracking-[0.13em] text-[#f36a21]">{currentQuestion.category || exam.category || 'Custom'}</p>
                  <h2 className="mt-1 text-lg font-black tracking-[-0.02em]">Question {currentIndex + 1}</h2>
                </div>
                <button
                  type="button"
                  onClick={() => setFlagged((state) => ({ ...state, [currentQuestion.id]: !state[currentQuestion.id] }))}
                  className={cn(
                    'inline-flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-bold shadow-sm transition',
                    flagged[currentQuestion.id] ? 'border-red-200 bg-red-50 text-red-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50',
                  )}
                >
                  <Flag className="size-3.5" /> {flagged[currentQuestion.id] ? 'Flagged' : 'Flag Question'}
                </button>
              </div>
              <div className="min-h-[430px] px-5 py-6">
                <p className="text-[0.95rem] font-black leading-7 text-slate-900">{currentQuestion.question}</p>
                <p className="mt-2 text-xs font-medium text-slate-500">{questionTypeLabel(currentQuestion.question_type)} · {currentQuestion.points} point(s)</p>

                {['multiple choice', 'true / false', 'true/false'].includes(normalizeQuestionType(currentQuestion.question_type)) ? (
                  <div className="mt-6 grid gap-4">
                    {(['true / false', 'true/false'].includes(normalizeQuestionType(currentQuestion.question_type)) ? ['True', 'False'] : currentQuestion.choices || []).map((choice, index) => (
                      <label
                        key={choice}
                        className={cn(
                          'flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-4 text-sm font-black transition',
                          answers[currentQuestion.id] === choice
                            ? 'border-[#f36a21] bg-orange-50 text-orange-800 shadow-[0_12px_26px_-24px_rgba(234,88,12,0.7)]'
                            : 'border-slate-200 bg-white text-slate-900 hover:border-orange-200 hover:bg-orange-50/40',
                        )}
                      >
                        <input
                          type="radio"
                          name={`q-${currentQuestion.id}`}
                          value={choice}
                          checked={answers[currentQuestion.id] === choice}
                          onChange={(e) => updateAnswer(currentQuestion.id, e.target.value)}
                          className="accent-[#f36a21]"
                        />
                        <span>{OPTION_LETTERS[index]}. {choice}</span>
                      </label>
                    ))}
                  </div>
                ) : normalizeQuestionType(currentQuestion.question_type) === 'checkbox' ? (
                  <div className="mt-6 grid gap-4">
                    {(currentQuestion.choices || []).map((choice, index) => (
                      <label
                        key={choice}
                        className={cn(
                          'flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-4 text-sm font-black transition',
                          (answers[currentQuestion.id] || []).includes(choice)
                            ? 'border-[#f36a21] bg-orange-50 text-orange-800'
                            : 'border-slate-200 bg-white text-slate-900 hover:border-orange-200 hover:bg-orange-50/40',
                        )}
                      >
                        <input
                          type="checkbox"
                          checked={(answers[currentQuestion.id] || []).includes(choice)}
                          onChange={() => toggleCheckbox(currentQuestion.id, choice)}
                          className="accent-[#f36a21]"
                        />
                        <span>{OPTION_LETTERS[index]}. {choice}</span>
                      </label>
                    ))}
                  </div>
                ) : normalizeQuestionType(currentQuestion.question_type) === 'file upload' ? (
                  <div className="mt-6 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-5">
                    <Input type="file" onChange={(e) => setFiles((state) => ({ ...state, [currentQuestion.id]: e.target.files?.[0] || null }))} />
                    {files[currentQuestion.id] ? <p className="mt-2 text-xs font-semibold text-slate-500">{files[currentQuestion.id].name}</p> : null}
                  </div>
                ) : ['identification', 'short answer'].includes(normalizeQuestionType(currentQuestion.question_type)) ? (
                  <Input className="mt-6 h-12 rounded-lg border-slate-200 text-sm font-semibold" value={answers[currentQuestion.id] || ''} onChange={(e) => updateAnswer(currentQuestion.id, e.target.value)} placeholder="Type your answer" />
                ) : (
                  <Textarea className="mt-6 min-h-44 rounded-lg border-slate-200 text-sm font-semibold" value={answers[currentQuestion.id] || ''} onChange={(e) => updateAnswer(currentQuestion.id, e.target.value)} placeholder="Type your answer" />
                )}

                {error ? (
                  <div className="mt-5 flex gap-2 rounded-lg border border-red-100 bg-red-50 p-3 text-xs font-semibold leading-5 text-red-700">
                    <AlertCircle className="mt-0.5 size-4 shrink-0" />
                    {error}
                  </div>
                ) : null}
              </div>
              <div className="flex items-center justify-between border-t border-slate-100 px-5 py-4">
                <Button variant="outline" className="h-10 gap-2 rounded-md border-slate-200 px-4 font-bold" onClick={() => setCurrentIndex((index) => Math.max(0, index - 1))} disabled={currentIndex === 0}>
                  <ChevronLeft className="size-4" /> Previous
                </Button>
                {currentIndex === questions.length - 1 ? (
                  <Button className="h-10 gap-2 rounded-md bg-[#ff5a14] px-5 font-bold text-white shadow-[0_12px_26px_-18px_rgba(249,115,22,0.9)] hover:bg-[#e94d0d]" onClick={submit} disabled={isSubmitting}>
                    <Send className="size-4" /> {isSubmitting ? 'Submitting...' : 'Submit Assessment'}
                  </Button>
                ) : (
                  <Button className="h-10 gap-2 rounded-md bg-[#ff5a14] px-5 font-bold text-white shadow-[0_12px_26px_-18px_rgba(249,115,22,0.9)] hover:bg-[#e94d0d]" onClick={() => setCurrentIndex((index) => Math.min(questions.length - 1, index + 1))}>
                    Next <ChevronRight className="size-4" />
                  </Button>
                )}
              </div>
            </>
          ) : (
            <div className="py-16 text-center text-sm font-semibold text-slate-500">No questions are available for this assessment.</div>
          )}
        </section>

        <aside className="rounded-[10px] border border-slate-200 bg-white p-5 shadow-[0_16px_42px_-32px_rgba(15,23,42,0.55)] lg:col-span-2 xl:col-span-1">
          <h2 className="text-sm font-black">Question Navigator</h2>
          <div className="mt-4 flex flex-wrap gap-3">
            {questions.map((question, index) => {
              const isAnswered = answerValueExists(answers[question.id]) || Boolean(files[question.id])
              return (
                <button
                  key={question.id}
                  type="button"
                  onClick={() => setCurrentIndex(index)}
                  className={cn(
                    'size-9 rounded-full text-xs font-black shadow-sm ring-1 transition',
                    flagged[question.id] && 'bg-rose-100 text-rose-700 ring-rose-200',
                    !flagged[question.id] && currentIndex === index && 'bg-[#ff5a14] text-white ring-[#ff5a14]',
                    !flagged[question.id] && currentIndex !== index && isAnswered && 'bg-emerald-100 text-emerald-700 ring-emerald-200',
                    !flagged[question.id] && currentIndex !== index && !isAnswered && 'bg-slate-100 text-slate-700 ring-slate-200',
                  )}
                >
                  {index + 1}
                </button>
              )
            })}
          </div>

          <div className="mt-5 space-y-3 border-b border-slate-100 pb-5 text-[0.68rem] font-semibold text-slate-500">
            <p><span className="mr-2 inline-block size-3 rounded-sm bg-slate-100 align-middle ring-1 ring-slate-200" /> Not Answered</p>
            <p><span className="mr-2 inline-block size-3 rounded-sm bg-[#ff5a14] align-middle" /> Current</p>
            <p><span className="mr-2 inline-block size-3 rounded-sm bg-emerald-100 align-middle ring-1 ring-emerald-200" /> Answered</p>
            <p><span className="mr-2 inline-block size-3 rounded-sm bg-rose-100 align-middle ring-1 ring-rose-200" /> Flagged</p>
          </div>

          <div className="mt-5">
            <h3 className="text-sm font-black">Exam Summary</h3>
            <div className="mt-4 space-y-3 text-[0.72rem] font-semibold text-slate-600">
              <div className="flex items-center justify-between gap-3">
                <span className="inline-flex items-center gap-2"><CheckCircle2 className="size-3.5 text-slate-500" /> Total Questions</span>
                <span className="font-black text-slate-900">{questions.length}</span>
              </div>
              <div className="flex items-center justify-between gap-3">
                <span className="inline-flex items-center gap-2"><CheckCircle2 className="size-3.5 text-slate-500" /> Answered</span>
                <span className="font-black text-slate-900">{answeredCount}</span>
              </div>
              <div className="flex items-center justify-between gap-3">
                <span className="inline-flex items-center gap-2"><AlertCircle className="size-3.5 text-slate-500" /> Not Answered</span>
                <span className="font-black text-slate-900">{notAnsweredCount}</span>
              </div>
              <div className="flex items-center justify-between gap-3">
                <span className="inline-flex items-center gap-2"><Clock className="size-3.5 text-slate-500" /> Time Remaining</span>
                <span className="font-black text-[#f36a21]">{timerLabel}</span>
              </div>
              <div>
                <div className="flex items-center justify-between gap-3">
                  <span className="inline-flex items-center gap-2"><ChevronRight className="size-3.5 text-slate-500" /> Progress</span>
                  <span className="font-black text-slate-900">{progress}%</span>
                </div>
                <div className="mt-2 h-1.5 rounded-full bg-slate-200">
                  <div className="h-1.5 rounded-full bg-[#ff5a14] transition-all" style={{ width: `${progress}%` }} />
                </div>
              </div>
            </div>
          </div>

          <Button className="mt-6 h-11 w-full gap-2 rounded-md bg-[#ff5a14] text-sm font-black text-white shadow-[0_16px_28px_-18px_rgba(249,115,22,0.95)] hover:bg-[#e94d0d]" onClick={submit} disabled={isSubmitting}>
            <Send className="size-4" />
            {isSubmitting ? 'Submitting...' : 'Submit Assessment'}
          </Button>
          {remainingSeconds === 0 ? (
            <div className="mt-3 flex gap-2 rounded-lg bg-red-50 p-3 text-xs font-semibold text-red-700"><AlertTriangle className="size-4 shrink-0" /> Time expired. Auto-submitting...</div>
          ) : null}
        </aside>
      </main>

      <footer className="pb-5 pt-24 text-center text-[0.65rem] font-medium text-slate-500">
        © 2026 AGCTEK Solutions Inc. All rights reserved.
      </footer>
    </div>
  )
}
