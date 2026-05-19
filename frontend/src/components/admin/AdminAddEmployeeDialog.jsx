import { cloneElement, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { AnimatePresence, motion as Motion } from 'framer-motion'
import {
  UserPlus,
  ScanFace,
  UserCheck,
  Search,
  Clock,
  KeyRound,
  CheckCircle2,
  AlertTriangle,
  Loader2,
  Info,
  UserRound,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Autocomplete } from '@react-google-maps/api'
import { mapPlaceToAddressFields } from '@/lib/googlePlaces'
import { useGoogleMapsLoader } from '@/hooks/useGoogleMapsLoader'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useToast } from '@/components/ui/use-toast'
import { useQueryClient } from '@tanstack/react-query'
import { addEmployee } from '@/api'
import ESignatureCard from '@/components/ESignatureCard'
import SignaturePadDialog from '@/components/SignaturePadDialog'
import { FIELD_SELECT_CLASS } from '@/lib/fieldClasses'
import { PasswordInput } from '@/components/ui/password-input'
import { cn } from '@/lib/utils'

function getAddEmployeeFriendlyError(error) {
  const raw = String(error?.message || '').toLowerCase()
  if (
    raw.includes('users_phone_number_unique') ||
    (raw.includes('duplicate entry') && raw.includes('phone_number')) ||
    raw.includes('phone number is already in use')
  ) {
    return 'This phone number is already used by another employee.'
  }
  if (raw.includes('users_email_unique') || (raw.includes('duplicate entry') && raw.includes('email'))) {
    return 'This email address is already used by another employee.'
  }
  if (raw.includes('users_username_unique') || (raw.includes('duplicate entry') && raw.includes('username'))) {
    return 'This username is already used by another employee.'
  }
  return error?.message || 'Failed to add employee. Please try again.'
}

function isValidEmailAddress(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim())
}

function isValidPhMobile(number) {
  return /^(\+63\s?9\d{9}|09\d{9})$/.test(String(number || '').trim())
}

function isValidUsername(value) {
  return /^[A-Za-z0-9._]+$/.test(String(value || '').trim())
}

function getPasswordStrength(password) {
  const value = String(password || '')
  if (!value) return { label: 'None', tone: 'text-muted-foreground' }

  let score = 0
  if (value.length >= 8) score += 1
  if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score += 1
  if (/\d/.test(value)) score += 1
  if (/[^A-Za-z0-9]/.test(value)) score += 1

  if (score <= 1) return { label: 'Weak', tone: 'text-rose-600' }
  if (score <= 3) return { label: 'Medium', tone: 'text-amber-600' }
  return { label: 'Strong', tone: 'text-emerald-600' }
}

const INITIAL_ADD_FORM = {
  first_name: '',
  middle_name: '',
  last_name: '',
  suffix: '',
  preferred_name: '',
  date_of_birth: '',
  gender: '',
  civil_status: '',
  nationality: '',
  street_address: '',
  barangay: '',
  city: '',
  province: '',
  postal_code: '',
  username: '',
  email: '',
  phone_number: '',
  branch_id: '',
  department_id: '',
  position: '',
  branch_office_location: '',
  employment_type: '',
  hire_date: '',
  supervisor_id: '',
  working_schedule_id: '',
  password: '',
  profile_photo: null,
}

const ADD_EMPLOYEE_CONTROL_CLASS =
  'h-12 rounded-xl border-border/70 bg-background px-4 text-sm text-foreground shadow-sm transition-colors placeholder:text-muted-foreground/70 focus-visible:ring-[3px] focus-visible:ring-brand/25 dark:border-border/60 dark:bg-input/25 dark:placeholder:text-muted-foreground/60'

function AddFormField({
  id,
  label,
  optional = false,
  required = false,
  icon: Icon,
  helper,
  children,
  className,
}) {
  return (
    <div className={cn('grid gap-2', className)}>
      <Label htmlFor={id} className="text-sm font-semibold text-foreground">
        {label}
        {required ? <span className="ml-1 text-foreground">*</span> : null}
        {optional ? <span className="ml-2 text-xs font-normal text-muted-foreground">(optional)</span> : null}
      </Label>
      <div className="relative">
        {Icon ? (
          <Icon className="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden />
        ) : null}
        {cloneElement(children, {
          id,
          className: cn(children.props.className, ADD_EMPLOYEE_CONTROL_CLASS, Icon && 'pl-12'),
        })}
      </div>
      {helper ? (
        <div className="flex items-start gap-2 text-sm leading-relaxed text-muted-foreground">
          <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
          <span>{helper}</span>
        </div>
      ) : null}
    </div>
  )
}

/**
 * Add-employee wizard isolated from the employees list so keystrokes do not re-render the full page.
 */
export function AdminAddEmployeeDialog({
  open,
  onOpenChange,
  branches,
  departments,
  workingSchedules,
  departmentsLoading,
  getSupervisorCandidatesByCompany,
  fetchEmployees,
}) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [addSubmitting, setAddSubmitting] = useState(false)
  const [addSignatureDataUrl, setAddSignatureDataUrl] = useState('')
  const [addSignatureDialogOpen, setAddSignatureDialogOpen] = useState(false)
  const [addForm, setAddForm] = useState(INITIAL_ADD_FORM)
  const [addStep, setAddStep] = useState(1)
  const [addStepDir, setAddStepDir] = useState(1)
  const [addConfirmPassword, setAddConfirmPassword] = useState('')
  const [addFormError, setAddFormError] = useState('')
  const addPhotoInputRef = useRef(null)
  const [addPhotoPreviewUrl, setAddPhotoPreviewUrl] = useState('')
  const addStreetAutocompleteRef = useRef(null)
  const addBarangayAutocompleteRef = useRef(null)
  const addCityAutocompleteRef = useRef(null)
  const addProvinceAutocompleteRef = useRef(null)
  const { isLoaded: isMapsLoaded, loadError: mapsLoadError } = useGoogleMapsLoader()

  const applyMappedAddAddress = useCallback(
    (place) => {
      try {
        const mapped = mapPlaceToAddressFields(place)
        setAddForm((prev) => ({
          ...prev,
          street_address: mapped.street_address || prev.street_address,
          barangay: mapped.barangay || '',
          city: mapped.city || '',
          province: mapped.province || '',
          postal_code: String(mapped.postal_code || '')
            .replace(/[^\d]/g, '')
            .slice(0, 4),
        }))
      } catch (e) {
        toast({
          title: 'Address autocomplete failed',
          description: e?.message || 'Unable to read selected address.',
          variant: 'destructive',
        })
      }
    },
    [toast]
  )

  const makeAddPlaceChangedHandler = useCallback(
    (ref) => () => {
      try {
        const instance = ref?.current
        if (!instance || typeof instance.getPlace !== 'function') return
        const place = instance.getPlace()
        if (!place) return
        applyMappedAddAddress(place)
      } catch (e) {
        toast({
          title: 'Address autocomplete error',
          description: e?.message || 'Something went wrong while selecting an address.',
          variant: 'destructive',
        })
      }
    },
    [applyMappedAddAddress, toast]
  )

  useEffect(() => {
    const file = addForm.profile_photo
    if (!file) {
      setAddPhotoPreviewUrl('')
      return
    }
    const objectUrl = URL.createObjectURL(file)
    setAddPhotoPreviewUrl(objectUrl)
    return () => URL.revokeObjectURL(objectUrl)
  }, [addForm.profile_photo])

  const addStepMeta = useMemo(
    () => [
      { step: 1, label: 'Identity', icon: ScanFace },
      { step: 2, label: 'Personal', icon: UserCheck },
      { step: 3, label: 'Contact', icon: Search },
      { step: 4, label: 'Employment', icon: Clock },
      { step: 5, label: 'Credentials', icon: KeyRound },
    ],
    []
  )

  const addSupervisorOptions = useMemo(
    () => getSupervisorCandidatesByCompany(addForm.department_id),
    [addForm.department_id, getSupervisorCandidatesByCompany]
  )

  const handleAddCompanyChange = (nextDepartmentId) => {
    setAddForm((prev) => {
      const scopedSupervisors = getSupervisorCandidatesByCompany(nextDepartmentId)
      const keepCurrentSupervisor = scopedSupervisors.some(
        (emp) => String(emp.id) === String(prev.supervisor_id)
      )
      return {
        ...prev,
        department_id: nextDepartmentId,
        supervisor_id: keepCurrentSupervisor
          ? prev.supervisor_id
          : (scopedSupervisors[0]?.id ? String(scopedSupervisors[0].id) : ''),
      }
    })
  }

  const submitAddEmployee = async () => {
    const phoneRaw = addForm.phone_number.trim().replace(/[^\d+\s]/g, '')
    const composedHomeAddress = [
      addForm.street_address?.trim(),
      addForm.barangay?.trim(),
      addForm.city?.trim(),
      addForm.province?.trim(),
      addForm.postal_code?.trim(),
    ]
      .filter(Boolean)
      .join(', ')
    if (!addForm.first_name.trim() || !addForm.last_name.trim()) {
      setAddFormError('First Name and Last Name are required.')
      return
    }
    const emailTrim = addForm.email.trim()
    if (emailTrim && !isValidEmailAddress(addForm.email)) {
      setAddFormError('Enter a valid email address.')
      return
    }
    if (!addForm.username.trim()) {
      setAddFormError('Username is required.')
      return
    }
    if (!isValidUsername(addForm.username)) {
      setAddFormError('Username can only contain letters, numbers, underscores, and dots (no spaces).')
      return
    }
    if (!phoneRaw) {
      setAddFormError('Contact Number is required.')
      return
    }
    if (!isValidPhMobile(phoneRaw)) {
      setAddFormError('Enter a valid Philippine mobile number (e.g. 09123456789 or +639123456789).')
      return
    }
    if (
      !addForm.street_address.trim() ||
      !addForm.barangay.trim() ||
      !addForm.city.trim() ||
      !addForm.province.trim() ||
      !addForm.postal_code.trim()
    ) {
      setAddFormError('Complete address is required (Street Address, Barangay, City, Province, Postal Code).')
      return
    }
    if (!addForm.password || addForm.password.length < 8) {
      setAddFormError('Password must be at least 8 characters.')
      return
    }
    if (addForm.password !== addConfirmPassword) {
      setAddFormError('Password and Confirm Password do not match.')
      return
    }
    setAddSubmitting(true)
    setAddFormError('')
    try {
      const selectedBranch = branches.find((b) => String(b.id) === String(addForm.branch_id))
      const derivedCompanyId =
        selectedBranch?.company_id != null && selectedBranch.company_id !== ''
          ? Number(selectedBranch.company_id)
          : undefined
      const created = await addEmployee({
        first_name: addForm.first_name.trim(),
        middle_name: addForm.middle_name.trim() || undefined,
        last_name: addForm.last_name.trim(),
        suffix: addForm.suffix.trim() || undefined,
        date_of_birth: addForm.date_of_birth?.trim() || undefined,
        gender: addForm.gender?.trim() || undefined,
        civil_status: addForm.civil_status?.trim() || undefined,
        nationality: addForm.nationality?.trim() || undefined,
        home_address: composedHomeAddress || undefined,
        full_address: composedHomeAddress || undefined,
        street_address: addForm.street_address?.trim() || undefined,
        barangay: addForm.barangay?.trim() || undefined,
        city: addForm.city?.trim() || undefined,
        province: addForm.province?.trim() || undefined,
        postal_code: addForm.postal_code?.trim() || undefined,
        username: addForm.username.trim(),
        email: emailTrim || undefined,
        phone_number: phoneRaw || undefined,
        company_id: derivedCompanyId,
        branch_id: addForm.branch_id || undefined,
        department_id: addForm.department_id || undefined,
        position: addForm.position.trim() || undefined,
        branch_office_location: addForm.branch_office_location.trim() || undefined,
        employment_type: addForm.employment_type || undefined,
        hire_date: addForm.hire_date || undefined,
        supervisor_id: addForm.supervisor_id || undefined,
        working_schedule_id: addForm.working_schedule_id || undefined,
        password: addForm.password,
        profile_photo: addForm.profile_photo || undefined,
        signature_data_url: addSignatureDataUrl || undefined,
      })
      const createdEmployee = created?.employee
      toast({
        title: 'Employee created',
        description: (createdEmployee?.name || 'New employee') + ' was added successfully.',
        variant: 'success',
      })
      setAddForm(INITIAL_ADD_FORM)
      setAddSignatureDataUrl('')
      setAddSignatureDialogOpen(false)
      setAddConfirmPassword('')
      setAddStep(1)
      setAddFormError('')
      onOpenChange(false)
      await queryClient.invalidateQueries({ queryKey: ['admin-employees-list'] })
      if (createdEmployee?.id) {
        await queryClient.invalidateQueries({
          queryKey: ['admin-employee-profile-snapshot', String(createdEmployee.id)],
        })
      }
      await fetchEmployees()
    } catch (e) {
      setAddFormError(getAddEmployeeFriendlyError(e))
    } finally {
      setAddSubmitting(false)
    }
  }

  const handleAddSubmit = async (e) => {
    e.preventDefault()
    await submitAddEmployee()
  }

  if (!open) return null

  return (
    <>
      {/* Add employee — single name row, sticky footer, visible helper text in dark */}
      <Dialog
        open={open}
        onOpenChange={(nextOpen) => {
          onOpenChange(nextOpen)
          if (!nextOpen) {
            setAddStepDir(1)
            setAddStep(1)
            setAddFormError('')
            setAddConfirmPassword('')
            setAddSignatureDataUrl('')
            setAddSignatureDialogOpen(false)
          }
        }}
      >
        <DialogContent
          overlayClassName="bg-black/60 backdrop-blur-[3px]"
          innerClassName="overflow-hidden p-0"
          closeButtonClassName="right-5 top-5 size-10 rounded-2xl border-border/70 bg-background/95 text-foreground shadow-sm hover:bg-muted dark:bg-card/95"
          className="admin-add-employee-dialog flex max-h-[min(88vh,800px)] w-[min(94vw,760px)]! max-w-[760px]! flex-col gap-0 overflow-hidden rounded-3xl border border-border/70 bg-card p-0 text-card-foreground shadow-2xl dark:shadow-black/50"
        >
          <DialogHeader className="shrink-0 gap-0 px-6 pb-4 pt-5 sm:px-8 sm:pt-6">
            <DialogTitle className="flex items-center gap-4 text-2xl font-extrabold tracking-tight text-foreground">
              <div className="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-brand/10 text-brand shadow-inner ring-1 ring-brand/15">
                <UserPlus className="size-7" aria-hidden />
              </div>
              <span>Add New Employee</span>
            </DialogTitle>
            <DialogDescription className="mt-3 text-sm leading-relaxed text-muted-foreground">
              Step {addStep} of 5 &middot;{' '}
              <span className="font-bold text-foreground">
                {addStepMeta[addStep - 1]?.label}
              </span>{' '}
              — Fill in the details to create a new HRIS record
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleAddSubmit} className="flex min-h-0 flex-1 flex-col overflow-hidden">
            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-4 sm:px-8">
              <div className="mb-6 border-b border-border/60">
                <div className="flex items-start justify-between gap-2">
                  {addStepMeta.map((item, idx) => {
                    const Icon = item.icon
                    const active = addStep === item.step
                    const done = addStep > item.step
                    return (
                      <button
                        key={item.step}
                        type="button"
                        className="group relative flex min-w-0 flex-1 flex-col items-center gap-2 px-1 pb-4 text-center focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-2 focus-visible:ring-offset-card"
                        onClick={() => {
                          if (item.step === addStep || item.step > addStep || addSubmitting) return
                          setAddFormError('')
                          setAddStepDir(item.step > addStep ? 1 : -1)
                          setAddStep(item.step)
                        }}
                        disabled={item.step > addStep || addSubmitting}
                        aria-current={active ? 'step' : undefined}
                      >
                        <span className={`flex size-11 items-center justify-center rounded-full transition-all duration-200 ${
                            active
                              ? 'bg-brand text-brand-foreground shadow-lg shadow-brand/25 ring-4 ring-brand/15'
                              : done
                                ? 'border border-brand/35 bg-brand/10 text-brand'
                                : 'border border-border bg-muted/45 text-foreground/80 group-hover:border-brand/35 group-hover:bg-brand/5 group-hover:text-brand'
                          }`}>
                            {done
                              ? <CheckCircle2 className="size-5" aria-hidden />
                              : <Icon className="size-5" aria-hidden />
                            }
                        </span>
                        <span className={`truncate text-[11px] font-extrabold uppercase tracking-wide sm:text-xs ${
                            active ? 'text-foreground' : done ? 'text-brand' : 'text-foreground/80'
                          }`}>
                            {item.label}
                        </span>
                        <span className={`absolute inset-x-0 -bottom-px h-0.5 rounded-full transition-all duration-300 ${
                          active ? 'bg-brand' : done ? 'bg-brand/45' : 'bg-transparent'
                        }`} />
                        {idx < addStepMeta.length - 1 ? (
                          <span className="pointer-events-none absolute right-0 top-5 hidden h-px w-full translate-x-1/2 bg-border/40 sm:block" aria-hidden />
                        ) : null}
                      </button>
                    )
                  })}
                </div>
              </div>

              {addFormError && (
                <div className="mb-5 flex items-start gap-3 rounded-2xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive dark:text-red-300" role="alert">
                  <AlertTriangle className="mt-0.5 size-5 shrink-0" aria-hidden />
                  <span>{addFormError}</span>
                </div>
              )}

              <AnimatePresence mode="wait" initial={false}>
              {addStep === 1 && (
                <Motion.div
                  key="add-step-1"
                  initial={{ opacity: 0, x: addStepDir > 0 ? 24 : -24 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: addStepDir > 0 ? -24 : 24 }}
                  transition={{ duration: 0.18 }}
                  className="space-y-4"
                >
                  <div className="flex items-start gap-4 rounded-2xl border border-brand/20 bg-brand/5 px-5 py-4 shadow-sm dark:border-brand/25 dark:bg-brand/10">
                    <AlertTriangle className="mt-1 size-7 shrink-0 text-brand" aria-hidden />
                    <div>
                      <p className="text-base font-extrabold text-brand">Legal Name Match Required</p>
                      <p className="mt-1 max-w-2xl text-sm leading-relaxed text-foreground">
                        Names must exactly match the employee&apos;s government-issued ID (passport, SSS, PhilSys). Incorrect entries may cause payroll and compliance issues.
                      </p>
                    </div>
                  </div>
                  <div className="grid grid-cols-1 gap-4">
                    <AddFormField id="add-first_name" label="First Name" required icon={UserRound}>
                      <Input
                        value={addForm.first_name}
                        onChange={(e) => setAddForm((f) => ({ ...f, first_name: e.target.value }))}
                        placeholder="e.g. Michael"
                        autoFocus
                        required
                      />
                    </AddFormField>
                    <AddFormField id="add-middle_name" label="Middle Name" optional icon={UserRound}>
                      <Input
                        value={addForm.middle_name}
                        onChange={(e) => setAddForm((f) => ({ ...f, middle_name: e.target.value }))}
                        placeholder="Leave blank if none"
                      />
                    </AddFormField>
                    <AddFormField id="add-last_name" label="Last Name" required icon={UserRound}>
                      <Input
                        value={addForm.last_name}
                        onChange={(e) => setAddForm((f) => ({ ...f, last_name: e.target.value }))}
                        placeholder="e.g. Scott"
                        required
                      />
                    </AddFormField>
                    <AddFormField id="add-suffix" label="Suffix" optional icon={UserRound}>
                      <Input
                        value={addForm.suffix}
                        onChange={(e) => setAddForm((f) => ({ ...f, suffix: e.target.value }))}
                        placeholder="e.g. Jr."
                      />
                    </AddFormField>
                    <AddFormField
                      id="add-preferred_name"
                      label="Preferred Name"
                      optional
                      icon={UserRound}
                      helper="Used in clock-ins, payslips, and internal communications."
                    >
                      <Input
                        value={addForm.preferred_name}
                        onChange={(e) => setAddForm((f) => ({ ...f, preferred_name: e.target.value }))}
                        placeholder="e.g. Mike"
                      />
                    </AddFormField>
                  </div>
                </Motion.div>
              )}

              {addStep === 2 && (
                <Motion.div
                  key="add-step-2"
                  initial={{ opacity: 0, x: addStepDir > 0 ? 24 : -24 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: addStepDir > 0 ? -24 : 24 }}
                  transition={{ duration: 0.18 }}
                  className="space-y-4"
                >
                  <div className="flex items-center gap-3">
                    <div className="h-px flex-1 bg-border/60" />
                    <span className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Personal Details</span>
                    <div className="h-px flex-1 bg-border/60" />
                  </div>
                  <div className="grid grid-cols-1 gap-4 @sm:grid-cols-2">
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-date_of_birth" className="text-[13px] font-medium">Date of Birth</Label>
                      <Input
                        id="add-date_of_birth"
                        type="date"
                        value={addForm.date_of_birth}
                        onChange={(e) => setAddForm((f) => ({ ...f, date_of_birth: e.target.value }))}
                        className="h-9 dark:scheme-dark"
                      />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-gender" className="text-[13px] font-medium">Gender</Label>
                      <select
                        id="add-gender"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.gender}
                        onChange={(e) => setAddForm((f) => ({ ...f, gender: e.target.value }))}
                      >
                        <option value="">Select gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                      </select>
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-civil_status" className="text-[13px] font-medium">Civil Status</Label>
                      <select
                        id="add-civil_status"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.civil_status}
                        onChange={(e) => setAddForm((f) => ({ ...f, civil_status: e.target.value }))}
                      >
                        <option value="">Select civil status</option>
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Widowed">Widowed</option>
                        <option value="Separated">Separated</option>
                      </select>
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-nationality" className="text-[13px] font-medium">Nationality</Label>
                      <select
                        id="add-nationality"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.nationality}
                        onChange={(e) => setAddForm((f) => ({ ...f, nationality: e.target.value }))}
                      >
                        <option value="">Select nationality</option>
                        <option value="Filipino">Filipino</option>
                        <option value="American">American</option>
                        <option value="Australian">Australian</option>
                        <option value="British">British</option>
                        <option value="Canadian">Canadian</option>
                        <option value="Chinese">Chinese</option>
                        <option value="Indian">Indian</option>
                        <option value="Indonesian">Indonesian</option>
                        <option value="Japanese">Japanese</option>
                        <option value="Korean">Korean</option>
                        <option value="Malaysian">Malaysian</option>
                        <option value="Singaporean">Singaporean</option>
                        <option value="Thai">Thai</option>
                        <option value="Vietnamese">Vietnamese</option>
                        <option value="Other">Other</option>
                      </select>
                    </div>
                  </div>
                </Motion.div>
              )}

              {addStep === 3 && (
                <Motion.div
                  key="add-step-3"
                  initial={{ opacity: 0, x: addStepDir > 0 ? 24 : -24 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: addStepDir > 0 ? -24 : 24 }}
                  transition={{ duration: 0.18 }}
                  className="space-y-4"
                >
                  <div className="flex items-center gap-3">
                    <div className="h-px flex-1 bg-border/60" />
                    <span className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Contact Information</span>
                    <div className="h-px flex-1 bg-border/60" />
                  </div>
                  <div className="grid grid-cols-1 gap-4 @sm:grid-cols-2">
                    <div className="grid gap-1.5 @sm:col-span-2">
                      <Label htmlFor="add-street_address" className="text-[13px] font-medium">Street Address</Label>
                      {isMapsLoaded ? (
                        <Autocomplete
                          onLoad={(ac) => {
                            addStreetAutocompleteRef.current = ac
                            try {
                              ac.setFields(['address_components', 'formatted_address', 'name'])
                              ac.setTypes(['address'])
                            } catch {
                              // ignore – some Google builds may not expose these setters
                            }
                          }}
                          onPlaceChanged={makeAddPlaceChangedHandler(addStreetAutocompleteRef)}
                          options={{
                            componentRestrictions: { country: 'ph' },
                          }}
                        >
                          <Input
                            id="add-street_address"
                            value={addForm.street_address}
                            onChange={(e) => setAddForm((f) => ({ ...f, street_address: e.target.value }))}
                            placeholder="Start typing to search an address..."
                            className="h-9"
                          />
                        </Autocomplete>
                      ) : (
                        <Input
                          id="add-street_address"
                          value={addForm.street_address}
                          onChange={(e) => setAddForm((f) => ({ ...f, street_address: e.target.value }))}
                          placeholder="Start typing to search an address..."
                          className="h-9"
                        />
                      )}
                      {mapsLoadError && (
                        <p className="text-xs text-amber-600">
                          Address autocomplete unavailable: {mapsLoadError}
                        </p>
                      )}
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-barangay" className="text-[13px] font-medium">Barangay</Label>
                      {isMapsLoaded ? (
                        <Autocomplete
                          onLoad={(ac) => {
                            addBarangayAutocompleteRef.current = ac
                            try {
                              ac.setFields(['address_components', 'formatted_address', 'name'])
                              ac.setTypes(['address'])
                            } catch {
                              // ignore
                            }
                          }}
                          onPlaceChanged={makeAddPlaceChangedHandler(addBarangayAutocompleteRef)}
                          options={{
                            componentRestrictions: { country: 'ph' },
                          }}
                        >
                          <Input
                            id="add-barangay"
                            value={addForm.barangay}
                            onChange={(e) => setAddForm((f) => ({ ...f, barangay: e.target.value }))}
                            placeholder="Start typing to search address..."
                            className="h-9"
                          />
                        </Autocomplete>
                      ) : (
                        <Input
                          id="add-barangay"
                          value={addForm.barangay}
                          onChange={(e) => setAddForm((f) => ({ ...f, barangay: e.target.value }))}
                          placeholder="Barangay"
                          className="h-9"
                        />
                      )}
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-city" className="text-[13px] font-medium">City</Label>
                      {isMapsLoaded ? (
                        <Autocomplete
                          onLoad={(ac) => {
                            addCityAutocompleteRef.current = ac
                            try {
                              ac.setFields(['address_components', 'formatted_address', 'name'])
                              ac.setTypes(['address'])
                            } catch {
                              // ignore
                            }
                          }}
                          onPlaceChanged={makeAddPlaceChangedHandler(addCityAutocompleteRef)}
                          options={{
                            componentRestrictions: { country: 'ph' },
                          }}
                        >
                          <Input
                            id="add-city"
                            value={addForm.city}
                            onChange={(e) => setAddForm((f) => ({ ...f, city: e.target.value }))}
                            placeholder="Start typing to search address..."
                            className="h-9"
                          />
                        </Autocomplete>
                      ) : (
                        <Input
                          id="add-city"
                          value={addForm.city}
                          onChange={(e) => setAddForm((f) => ({ ...f, city: e.target.value }))}
                          placeholder="City / Municipality"
                          className="h-9"
                        />
                      )}
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-province" className="text-[13px] font-medium">Province</Label>
                      {isMapsLoaded ? (
                        <Autocomplete
                          onLoad={(ac) => {
                            addProvinceAutocompleteRef.current = ac
                            try {
                              ac.setFields(['address_components', 'formatted_address', 'name'])
                              ac.setTypes(['address'])
                            } catch {
                              // ignore
                            }
                          }}
                          onPlaceChanged={makeAddPlaceChangedHandler(addProvinceAutocompleteRef)}
                          options={{
                            componentRestrictions: { country: 'ph' },
                          }}
                        >
                          <Input
                            id="add-province"
                            value={addForm.province}
                            onChange={(e) => setAddForm((f) => ({ ...f, province: e.target.value }))}
                            placeholder="Start typing to search address..."
                            className="h-9"
                          />
                        </Autocomplete>
                      ) : (
                        <Input
                          id="add-province"
                          value={addForm.province}
                          onChange={(e) => setAddForm((f) => ({ ...f, province: e.target.value }))}
                          placeholder="Province"
                          className="h-9"
                        />
                      )}
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-postal_code" className="text-[13px] font-medium">Postal Code</Label>
                      <Input
                        id="add-postal_code"
                        value={addForm.postal_code}
                        onChange={(e) => setAddForm((f) => ({ ...f, postal_code: e.target.value.replace(/[^\d]/g, '').slice(0, 4) }))}
                        placeholder="e.g. 1200"
                        className="h-9 focus-visible:ring-ring/50"
                        inputMode="numeric"
                      />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-phone_number" className="text-[13px] font-medium">Contact Number <span className="text-muted-foreground">*</span></Label>
                      <Input
                        id="add-phone_number"
                        type="tel"
                        value={addForm.phone_number}
                        onChange={(e) => setAddForm((f) => ({ ...f, phone_number: e.target.value.replace(/[^\d+\s]/g, '') }))}
                        placeholder="09123456789 or +639123456789"
                        className="h-9 focus-visible:ring-ring/50"
                        required
                      />
                      {addForm.phone_number.trim() !== '' && (
                        <p className={`text-xs ${isValidPhMobile(addForm.phone_number) ? 'text-emerald-600' : 'text-amber-600'}`}>
                          {isValidPhMobile(addForm.phone_number)
                            ? `${addForm.phone_number.trim()} ✓ Valid PH number`
                            : 'Use 09123456789 or +639123456789'}
                        </p>
                      )}
              </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-username" className="text-[13px] font-medium">Username <span className="text-muted-foreground">*</span></Label>
                      <Input
                        id="add-username"
                        type="text"
                        value={addForm.username}
                        onChange={(e) => setAddForm((f) => ({ ...f, username: e.target.value }))}
                        placeholder="e.g., neziahpaul or npbernabé"
                        className="h-9 focus-visible:ring-ring/50"
                        required
                      />
                      <p className="text-xs text-muted-foreground">Used for login (with password). Add email if the employee should receive mail or use email login.</p>
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-email" className="text-[13px] font-medium">
                        Email Address <span className="text-[10px] font-normal text-muted-foreground">(optional)</span>
                      </Label>
                      <Input
                        id="add-email"
                        type="email"
                        value={addForm.email}
                        onChange={(e) => setAddForm((f) => ({ ...f, email: e.target.value }))}
                        placeholder="juan@company.com"
                        className="h-9 focus-visible:ring-ring/50"
                      />
                      {addForm.email.trim() !== '' && (
                        <p className={`text-xs ${isValidEmailAddress(addForm.email) ? 'text-emerald-600' : 'text-amber-600'}`}>
                          {isValidEmailAddress(addForm.email)
                            ? `${addForm.email.trim()} ✓`
                            : 'Enter a valid email address'}
                        </p>
                      )}
              </div>
                  </div>
                </Motion.div>
              )}

              {addStep === 4 && (
                <Motion.div
                  key="add-step-4"
                  initial={{ opacity: 0, x: addStepDir > 0 ? 24 : -24 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: addStepDir > 0 ? -24 : 24 }}
                  transition={{ duration: 0.18 }}
                  className="space-y-4"
                >
                  <div className="flex items-center gap-3">
                    <div className="h-px flex-1 bg-border/60" />
                    <span className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Employment Details</span>
                    <div className="h-px flex-1 bg-border/60" />
                  </div>
                  <div className="grid grid-cols-1 gap-4 @sm:grid-cols-2">
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-employee-id" className="text-[13px] font-medium text-muted-foreground">Employee ID</Label>
                      <Input id="add-employee-id" value="Auto-generated on save" className="h-9 opacity-60" disabled />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-branch_id" className="text-[13px] font-medium">Branch</Label>
                      <select
                        id="add-branch_id"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.branch_id}
                        onChange={(e) => { const bid = e.target.value; setAddForm((f) => ({ ...f, branch_id: bid, department_id: '' })) }}
                        disabled={departmentsLoading}
                      >
                        <option value="">Select branch (optional)</option>
                        {branches.map((b) => (
                          <option key={b.id} value={b.id}>{b.name}{b.company_name ? ` — ${b.company_name}` : ''}</option>
                        ))}
                      </select>
                      {addForm.branch_id && (() => { const b = branches.find((x) => String(x.id) === String(addForm.branch_id)); return b?.company_name ? (<p className="mt-1 text-xs text-muted-foreground">Company: <span className="font-medium text-foreground">{b.company_name}</span></p>) : null })()}
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-department_id" className="text-[13px] font-medium">Department</Label>
                      <select
                        id="add-department_id"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.department_id}
                        onChange={(e) => handleAddCompanyChange(e.target.value)}
                        disabled={departmentsLoading}
                      >
                        <option value="">Select department</option>
                        {(addForm.branch_id ? departments.filter((d) => String(d.branch_id) === String(addForm.branch_id)) : departments).map((dept) => (
                          <option key={dept.id} value={dept.id}>{dept.name}</option>
                        ))}
                      </select>
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-position" className="text-[13px] font-medium">Job Title / Position</Label>
                      <Input
                        id="add-position"
                        value={addForm.position}
                        onChange={(e) => setAddForm((f) => ({ ...f, position: e.target.value }))}
                        placeholder="e.g. Software Engineer"
                        className="h-9 focus-visible:ring-ring/50"
                      />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-branch_office_location" className="text-[13px] font-medium">Office Location <span className="font-normal text-muted-foreground">(optional)</span></Label>
                      <Input
                        id="add-branch_office_location"
                        value={addForm.branch_office_location}
                        onChange={(e) => setAddForm((f) => ({ ...f, branch_office_location: e.target.value }))}
                        placeholder="e.g. 3rd Floor, Tower 2"
                        className="h-9 focus-visible:ring-ring/50"
                      />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-employment_type" className="text-[13px] font-medium">Employment Type</Label>
                      <select
                        id="add-employment_type"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.employment_type}
                        onChange={(e) => setAddForm((f) => ({ ...f, employment_type: e.target.value }))}
                      >
                        <option value="">Select employment type</option>
                        <option value="full_time">Full-time</option>
                        <option value="part_time">Part-time</option>
                        <option value="contract">Contract</option>
                        <option value="probationary">Probationary</option>
                      </select>
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-hire_date" className="text-[13px] font-medium">Hire Date</Label>
                      <Input
                        id="add-hire_date"
                        type="date"
                        value={addForm.hire_date}
                        onChange={(e) => setAddForm((f) => ({ ...f, hire_date: e.target.value }))}
                        className="h-9 dark:scheme-dark"
                      />
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-supervisor_id" className="text-[13px] font-medium">Supervisor</Label>
                      <select
                        id="add-supervisor_id"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.supervisor_id}
                        onChange={(e) => setAddForm((f) => ({ ...f, supervisor_id: e.target.value }))}
                      >
                        <option value="">Select supervisor</option>
                        {addSupervisorOptions.length === 0 && (
                          <option value="" disabled>No managerial supervisor available for selected department</option>
                        )}
                        {addSupervisorOptions.map((emp) => (
                          <option key={emp.id} value={emp.id}>
                            {emp.name} {emp.position ? `(${emp.position})` : ''}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div className="grid gap-1.5">
                      <Label htmlFor="add-working_schedule_id" className="text-[13px] font-medium">Work Schedule</Label>
                      <select
                        id="add-working_schedule_id"
                        className={FIELD_SELECT_CLASS}
                        value={addForm.working_schedule_id}
                        onChange={(e) => setAddForm((f) => ({ ...f, working_schedule_id: e.target.value }))}
                      >
                        <option value="">Select work schedule</option>
                        {workingSchedules.map((s) => (
                          <option key={s.id} value={s.id}>{s.name}</option>
                        ))}
                      </select>
                    </div>
                  </div>
                </Motion.div>
              )}

              {addStep === 5 && (
                <Motion.div
                  key="add-step-5"
                  initial={{ opacity: 0, x: addStepDir > 0 ? 24 : -24 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: addStepDir > 0 ? -24 : 24 }}
                  transition={{ duration: 0.18 }}
                  className="space-y-4"
                >
                  <div className="flex items-center gap-3">
                    <div className="h-px flex-1 bg-border/60" />
                    <span className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Account Credentials</span>
                    <div className="h-px flex-1 bg-border/60" />
                  </div>
                  <div className="grid gap-1.5">
                    <Label htmlFor="add-password" className="text-[13px] font-medium">Password <span className="text-muted-foreground">*</span></Label>
                    <PasswordInput
                      id="add-password"
                      value={addForm.password}
                      onChange={(e) => setAddForm((f) => ({ ...f, password: e.target.value }))}
                      placeholder="Min. 8 characters"
                      minLength={8}
                      className="h-9 focus-visible:ring-ring/50"
                      required
                    />
                    {addForm.password !== '' && (
                      <p className={`text-xs ${getPasswordStrength(addForm.password).tone}`}>
                        Password strength: {getPasswordStrength(addForm.password).label}
                      </p>
                    )}
                  </div>
                  <div className="grid gap-1.5">
                    <Label htmlFor="add-confirm-password" className="text-[13px] font-medium">Confirm Password <span className="text-muted-foreground">*</span></Label>
                    <PasswordInput
                      id="add-confirm-password"
                      value={addConfirmPassword}
                      onChange={(e) => setAddConfirmPassword(e.target.value)}
                      placeholder="Re-enter password"
                      minLength={8}
                      className="h-9 focus-visible:ring-ring/50"
                      required
                    />
                    {addConfirmPassword !== '' && (
                      <p className={`text-xs ${addConfirmPassword === addForm.password ? 'text-emerald-500' : 'text-red-400'}`}>
                        {addConfirmPassword === addForm.password ? '✓ Passwords match' : 'Passwords do not match'}
                      </p>
                    )}
                  </div>
                  <div className="grid gap-1.5">
                    <Label htmlFor="add-profile-photo" className="text-[13px] font-medium text-muted-foreground">Profile Photo <span className="text-[10px] font-normal">(optional)</span></Label>
                    <div className="flex items-center gap-3 rounded-lg border border-border/60 bg-muted/20 dark:border-slate-700/50 dark:bg-slate-800/20 p-3">
                      <Avatar className="h-12 w-12">
                        <AvatarImage src={addPhotoPreviewUrl || undefined} alt="Profile photo preview" />
                        <AvatarFallback>
                          {`${addForm.first_name?.[0] || ''}${addForm.last_name?.[0] || ''}`.trim() || 'U'}
                        </AvatarFallback>
                      </Avatar>
                      <div className="space-y-1">
                        <Button type="button" variant="outline" className="h-8" onClick={() => addPhotoInputRef.current?.click()}>
                          Upload
                        </Button>
                        {addForm.profile_photo && (
                          <p className="text-xs text-muted-foreground">{addForm.profile_photo.name}</p>
                        )}
                      </div>
                    </div>
                    <Input
                      ref={addPhotoInputRef}
                      id="add-profile-photo"
                      type="file"
                      accept="image/png,image/jpeg,image/jpg,image/webp,image/gif"
                      className="hidden"
                      onChange={(e) => {
                        const file = e.target.files?.[0] || null
                        setAddForm((f) => ({ ...f, profile_photo: file }))
                      }}
                    />
                  </div>
                  <ESignatureCard
                    title="Electronic Signature"
                    status={addSignatureDataUrl ? 'completed' : 'none'}
                    signatureImage={addSignatureDataUrl || ''}
                    busy={addSubmitting}
                    onManage={() => setAddSignatureDialogOpen(true)}
                    manageLabel={addSignatureDataUrl ? 'Update Signature' : 'Manage Signature'}
                  />
                  <p className="text-xs text-muted-foreground">
                    Draw the e-signature before submitting. It will be saved with the employee profile.
                  </p>
                </Motion.div>
              )}
              </AnimatePresence>
            </div>
            <DialogFooter className="shrink-0 flex-row items-center justify-between border-t border-border/60 bg-card px-6 py-4 sm:px-8">
              <Button
                type="button"
                variant="ghost"
                className="h-11 rounded-2xl px-5 text-sm font-semibold text-foreground hover:bg-muted hover:text-foreground"
                onClick={() => onOpenChange(false)}
              >
                Cancel
              </Button>
              <div className="flex items-center gap-2">
                {addStep > 1 && (
                  <Button
                    type="button"
                    variant="outline"
                    className="h-11 rounded-2xl border-border/70 px-5 text-sm font-semibold dark:border-border/60 dark:text-foreground dark:hover:bg-muted"
                    onClick={() => {
                      setAddFormError('')
                      setAddStepDir(-1)
                      setAddStep((s) => s - 1)
                    }}
                  >
                    Back
                  </Button>
                )}
                {addStep < 5 ? (
                  <Button
                    type="button"
                    className="h-11 rounded-2xl bg-brand px-6 text-sm font-extrabold text-brand-foreground shadow-lg shadow-brand/20 transition-all hover:bg-brand-strong hover:shadow-xl hover:shadow-brand/25"
                    onClick={() => {
                      if (addStep === 1) {
                        if (!addForm.first_name.trim() || !addForm.last_name.trim()) {
                          setAddFormError('First Name and Last Name are required.')
                          return
                        }
                      }
                      if (addStep === 3) {
                        const phoneRaw = addForm.phone_number.trim().replace(/[^\d+\s]/g, '')
                        if (addForm.email.trim() && !isValidEmailAddress(addForm.email)) {
                          setAddFormError('Enter a valid email address.')
                          return
                        }
                        if (!phoneRaw) {
                          setAddFormError('Contact Number is required.')
                          return
                        }
                        if (!isValidPhMobile(phoneRaw)) {
                          setAddFormError('Enter a valid Philippine mobile number (e.g. 09123456789 or +639123456789).')
                          return
                        }
                      }
                      setAddFormError('')
                      setAddStepDir(1)
                      setAddStep((s) => s + 1)
                    }}
                  >
                    Next Step
                    <span aria-hidden>→</span>
                  </Button>
                ) : (
                  <Button
                    type="submit"
                    disabled={addSubmitting}
                    className="h-11 min-w-[155px] rounded-2xl bg-brand px-6 text-sm font-extrabold text-brand-foreground shadow-lg shadow-brand/20 transition-all hover:bg-brand-strong hover:shadow-xl hover:shadow-brand/25 disabled:opacity-60"
                  >
                    {addSubmitting
                      ? <><Loader2 className="size-4 animate-spin" /> Adding…</>
                      : <><UserPlus className="size-4" /> Add Employee</>
                    }
                  </Button>
                )}
              </div>
            </DialogFooter>
          </form>
          <SignaturePadDialog
            open={addSignatureDialogOpen}
            onOpenChange={setAddSignatureDialogOpen}
            initialImage={addSignatureDataUrl}
            busy={addSubmitting}
            onSave={async (dataUrl) => {
              setAddSignatureDataUrl(dataUrl)
              setAddSignatureDialogOpen(false)
            }}
            onRemove={addSignatureDataUrl ? async () => {
              setAddSignatureDataUrl('')
              setAddSignatureDialogOpen(false)
            } : null}
          />
        </DialogContent>
      </Dialog>
    </>
  )
}
