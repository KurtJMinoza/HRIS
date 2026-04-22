import { useMemo, useState, useEffect, useRef } from 'react'
import {
  Camera,
  Loader2,
  User,
  Eye,
  EyeOff,
  Briefcase,
  ShieldCheck,
  ScanFace,
  Building2,
  IdCard,
  MapPin,
  CalendarDays,
  Clock3,
  Cake,
  VenusAndMars,
  Heart,
  Flag,
  House,
  FileText,
  Shield,
  Sparkles,
  Phone,
  LockKeyhole,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Badge } from '@/components/ui/badge'
import { RoleBadge } from '@/components/RoleBadge'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
  ADMIN_FORM_DIALOG_MAX_W_LG,
} from '@/lib/adminFormDialogStyles'
import { cn } from '@/lib/utils'
import { useAuth } from '@/contexts/AuthContext'
import {
  updateProfile,
  uploadProfilePhoto,
  removeProfilePhoto,
  getMyFace,
  profileImageUrl,
} from '@/api'
import { FaceRekognitionLiveness } from '@/components/FaceRekognitionLiveness'
import {
  validateEmail,
  validatePassword,
  validateConfirmPassword,
  validatePhone,
  sanitizeEmail,
  sanitizePassword,
} from '@/validation'
import { formatScheduleLabel12h } from '@/lib/timeFormat'

const ACCEPT_IMAGE = 'image/jpeg,image/jpg,image/png,image/gif,image/webp'
const MAX_FILE_MB = 2
const PROFILE_EXTRAS_KEY_PREFIX = 'employee-profile-extras:'

function extrasKey(userId) {
  return `${PROFILE_EXTRAS_KEY_PREFIX}${userId}`
}

function hasText(value) {
  return String(value || '').trim() !== ''
}

function createEmptyGovIds() {
  return { tin: '', sss: '', philhealth: '', pagibig: '' }
}

function createEmptyEmergencyContact() {
  return { id: '', full_name: '', relationship: '', phone_number: '', address: '', is_primary: false }
}

function sanitizeAsciiByRegex(value, allowedCharRegex, maxLength = 40) {
  const source = String(value || '').replace(/[^\x20-\x7E]/g, '')
  let out = ''
  for (const ch of source) if (allowedCharRegex.test(ch)) out += ch
  return out.slice(0, maxLength)
}

function sanitizeGovIdByField(fieldName, value) {
  const source = String(value || '').replace(/[^\x20-\x7E]/g, '')
  const digitsAndHyphen = source.replace(/[^0-9-]/g, '')
  const limits = { tin: 15, sss: 12, philhealth: 14, pagibig: 14 }
  return digitsAndHyphen.slice(0, limits[fieldName] || 24)
}

function validateGovIdByField(fieldName, value) {
  const v = String(value || '').trim()
  if (!v) return ''
  const formats = {
    tin: /^\d{3}-\d{3}-\d{3}-\d{3}$/,
    sss: /^\d{2}-\d{7}-\d{1}$/,
    philhealth: /^\d{2}-\d{9}-\d{1}$/,
    pagibig: /^\d{4}-\d{4}-\d{4}$/,
  }
  const helpText = {
    tin: 'Use format 000-000-000-000.',
    sss: 'Use format 00-0000000-0.',
    philhealth: 'Use format 00-000000000-0.',
    pagibig: 'Use format 0000-0000-0000.',
  }
  if (!formats[fieldName]?.test(v)) return helpText[fieldName] || 'Invalid ID format.'
  return ''
}

function formatEmploymentType(value) {
  if (!value) return '—'
  return ({
    full_time: 'Full-time',
    part_time: 'Part-time',
    contract: 'Contract',
    probationary: 'Probationary',
  }[value] || value)
}

function formatLocalPhoneDisplay(digits) {
  const clean = String(digits || '').replace(/\D/g, '').slice(0, 10)
  if (clean.length <= 3) return clean
  if (clean.length <= 6) return `${clean.slice(0, 3)} ${clean.slice(3)}`
  return `${clean.slice(0, 3)} ${clean.slice(3, 6)} ${clean.slice(6)}`
}

export default function Profile() {
  const { user, setUser } = useAuth()
  const navigate = useNavigate()
  const [name, setName] = useState('')
  const [nameError, setNameError] = useState('')
  const [nameSuccess, setNameSuccess] = useState(false)
  const [nameLoading, setNameLoading] = useState(false)
  const [email, setEmail] = useState('')
  const [emailError, setEmailError] = useState('')
  const [emailSuccess, setEmailSuccess] = useState(false)
  const [emailLoading, setEmailLoading] = useState(false)

  const [phone, setPhone] = useState('')
  const [phoneError, setPhoneError] = useState('')
  const [phoneSuccess, setPhoneSuccess] = useState(false)
  const [phoneLoading, setPhoneLoading] = useState(false)

  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [passwordErrors, setPasswordErrors] = useState({ current: '', new: '', confirm: '' })
  const [passwordSuccess, setPasswordSuccess] = useState(false)
  const [passwordLoading, setPasswordLoading] = useState(false)

  const [photoLoading, setPhotoLoading] = useState(false)
  const [photoError, setPhotoError] = useState('')
  const fileInputRef = useRef(null)

  const [showCurrentPassword, setShowCurrentPassword] = useState(false)
  const [showNewPassword, setShowNewPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)

  const [livenessOpen, setLivenessOpen] = useState(false)
  const [pendingUpdate, setPendingUpdate] = useState(null)

  const [faceImage, setFaceImage] = useState(null)
  const [faceLoading, setFaceLoading] = useState(false)
  const [hasFace, setHasFace] = useState(user?.has_face ?? false)
  const [activeTab, setActiveTab] = useState('personal')

  // Employee-facing (local) profile extras: skills, certifications, gov IDs, emergency contacts
  const [skills, setSkills] = useState([])
  const [skillInput, setSkillInput] = useState('')
  const [certDocs, setCertDocs] = useState([])
  const certFileInputRef = useRef(null)

  const [govIds, setGovIds] = useState(createEmptyGovIds())
  const [govIdErrors, setGovIdErrors] = useState(createEmptyGovIds())

  const [emergencyContacts, setEmergencyContacts] = useState([])
  const [emergencyForm, setEmergencyForm] = useState(createEmptyEmergencyContact())
  const [editingEmergencyId, setEditingEmergencyId] = useState('')
  const [emergencyErrors, setEmergencyErrors] = useState({})

  // Sync email and phone from auth user so Profile shows saved values (e.g. after login or refresh)
  useEffect(() => {
    if (!user) return
    if (user.name !== undefined && user.name !== null) setName(user.name)
    if (user.email !== undefined && user.email !== null) setEmail(user.email)
    if (user.phone_number !== undefined) setPhone(user.phone_number ?? '')
    setHasFace(user.has_face ?? false)
  }, [user])

  useEffect(() => {
    if (!user?.id) return
    try {
      const raw = localStorage.getItem(extrasKey(user.id))
      const parsed = raw ? JSON.parse(raw) : null
      if (Array.isArray(parsed?.skills)) setSkills(parsed.skills.filter(Boolean))
      if (Array.isArray(parsed?.cert_docs)) setCertDocs(parsed.cert_docs)
      if (parsed?.government_ids && typeof parsed.government_ids === 'object') {
        setGovIds({
          tin: String(parsed.government_ids.tin || ''),
          sss: String(parsed.government_ids.sss || ''),
          philhealth: String(parsed.government_ids.philhealth || ''),
          pagibig: String(parsed.government_ids.pagibig || ''),
        })
      }
      if (Array.isArray(parsed?.emergency_contacts)) setEmergencyContacts(parsed.emergency_contacts)
    } catch {
      // ignore
    }
  }, [user?.id])

  useEffect(() => {
    if (!user?.id) return
    try {
      localStorage.setItem(
        extrasKey(user.id),
        JSON.stringify({
          skills,
          cert_docs: certDocs,
          government_ids: govIds,
          emergency_contacts: emergencyContacts,
        })
      )
    } catch {
      // ignore
    }
  }, [user?.id, skills, certDocs, govIds, emergencyContacts])

  function validateName(value) {
    const trimmed = String(value ?? '').trim()
    if (!trimmed) return 'Name is required.'
    if (trimmed.length > 255) return 'Name must be 255 characters or less.'
    if (!/^[A-Za-z0-9\s\-']+$/.test(trimmed)) return 'Name may only contain letters, numbers, spaces, hyphens, and apostrophes.'
    return ''
  }

  function handleAddSkill() {
    const value = String(skillInput || '').trim()
    if (!value) return
    if (skills.some((s) => String(s).toLowerCase() === value.toLowerCase())) return
    setSkills((prev) => [...prev, value])
    setSkillInput('')
  }

  function removeSkill(skill) {
    setSkills((prev) => prev.filter((s) => s !== skill))
  }

  function handleCertFilesSelected(e) {
    const files = Array.from(e.target.files || [])
    e.target.value = ''
    if (files.length === 0) return
    const now = new Date().toISOString()
    const added = files.map((file) => ({
      id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
      name: file.name,
      size: file.size,
      type: file.type || 'application/octet-stream',
      uploaded_at: now,
    }))
    setCertDocs((prev) => [...added, ...prev])
  }

  function removeCertDoc(id) {
    setCertDocs((prev) => prev.filter((d) => d.id !== id))
  }

  function updateGovId(fieldName, value) {
    const sanitized = sanitizeGovIdByField(fieldName, value)
    setGovIds((prev) => ({ ...prev, [fieldName]: sanitized }))
    setGovIdErrors((prev) => ({ ...prev, [fieldName]: validateGovIdByField(fieldName, sanitized) }))
  }

  function validateEmergency(payload) {
    const errors = {}
    const phoneRegex = /^[+0-9()\-.\s]{7,20}$/
    if (!hasText(payload.full_name)) errors.full_name = 'Full name is required.'
    if (!hasText(payload.relationship)) errors.relationship = 'Relationship is required.'
    if (!hasText(payload.phone_number)) errors.phone_number = 'Phone number is required.'
    else if (!phoneRegex.test(String(payload.phone_number).trim())) errors.phone_number = 'Use a valid phone format.'
    if (!hasText(payload.address)) errors.address = 'Address is required.'
    return errors
  }

  function startAddEmergency() {
    setEditingEmergencyId('')
    setEmergencyForm(createEmptyEmergencyContact())
    setEmergencyErrors({})
  }

  function startEditEmergency(contact) {
    setEditingEmergencyId(contact.id)
    setEmergencyForm({ ...createEmptyEmergencyContact(), ...contact })
    setEmergencyErrors({})
  }

  function saveEmergency() {
    const errs = validateEmergency(emergencyForm)
    setEmergencyErrors(errs)
    if (Object.keys(errs).length) return
    const payload = {
      id: editingEmergencyId || `emergency-${Date.now()}`,
      full_name: emergencyForm.full_name.trim(),
      relationship: emergencyForm.relationship.trim(),
      phone_number: emergencyForm.phone_number.trim(),
      address: emergencyForm.address.trim(),
      is_primary: emergencyForm.is_primary || emergencyContacts.length === 0,
    }
    setEmergencyContacts((prev) => {
      const exists = prev.some((c) => c.id === payload.id)
      let next = exists ? prev.map((c) => (c.id === payload.id ? payload : c)) : [...prev, payload]
      if (payload.is_primary) next = next.map((c) => ({ ...c, is_primary: c.id === payload.id }))
      return next
    })
  }

  function removeEmergency(id) {
    setEmergencyContacts((prev) => prev.filter((c) => c.id !== id))
    if (editingEmergencyId === id) startAddEmergency()
  }

  // Load face image for employees with registered face
  useEffect(() => {
    if (!user?.has_face || user?.role !== 'employee') {
      setFaceImage(null)
      return
    }
    setFaceLoading(true)
    getMyFace()
      .then((data) => {
        setFaceImage(data.face_image)
        setHasFace(data.has_face ?? false)
      })
      .catch(() => setFaceImage(null))
      .finally(() => setFaceLoading(false))
  }, [user?.id, user?.has_face, user?.role])

  const initials = user?.name
    ? user.name.trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2)
    : '?'

  const photoUrl = profileImageUrl(user?.profile_image)

  // —— Name ———
  function handleNameChange(e) {
    const next = e.target.value
    setName(next)
    setNameError(validateName(next))
    setNameSuccess(false)
  }

  async function handleNameSubmit(e) {
    e.preventDefault()
    const err = validateName(name)
    setNameError(err)
    if (err) return
    if (name.trim() === (user?.name || '').trim()) {
      setNameError('Enter a new name to change.')
      return
    }
    setNameLoading(true)
    setNameError('')
    try {
      const data = await updateProfile({ name: name.trim() })
      setUser(data.user)
      setNameSuccess(true)
    } catch (err2) {
      setNameError(err2?.message || 'Failed to update name')
    } finally {
      setNameLoading(false)
    }
  }

  // —— Email ———
  function handleEmailChange(e) {
    const next = sanitizeEmail(e.target.value)
    setEmail(next)
    setEmailError(validateEmail(next))
    setEmailSuccess(false)
  }

  function handleEmailSubmit(e) {
    e.preventDefault()
    const err = validateEmail(email)
    setEmailError(err)
    if (err) return
    if (email === user?.email) {
      setEmailError('Enter a new email address to change.')
      return
    }
    setPendingUpdate({ type: 'email', payload: { email: email.trim() } })
    setLivenessOpen(true)
  }

  function handlePhoneSubmit(e) {
    e.preventDefault()
    const err = validatePhone(phone, false)
    setPhoneError(err)
    if (err && phone.trim() !== '') return
    setPendingUpdate({ type: 'phone', payload: { phone_number: phone.trim() || null } })
    setLivenessOpen(true)
  }

  // —— Password ———
  function validatePasswordForm() {
    const current = !currentPassword ? 'Current password is required.' : ''
    const newErr = validatePassword(newPassword, true)
    const confirmErr = newPassword ? validateConfirmPassword(newPassword, confirmPassword) : ''
    setPasswordErrors({ current: current, new: newErr, confirm: confirmErr })
    return !current && !newErr && !confirmErr
  }

  function handlePasswordChange(field, value) {
    if (field === 'current') {
      setCurrentPassword(value)
      setPasswordErrors((prev) => ({ ...prev, current: value ? '' : prev.current }))
    } else if (field === 'new') {
      const next = sanitizePassword(value)
      setNewPassword(next)
      setPasswordSuccess(false)
      setPasswordErrors((prev) => ({
        ...prev,
        new: validatePassword(next, true),
        confirm: confirmPassword ? validateConfirmPassword(next, confirmPassword) : prev.confirm,
      }))
    } else {
      const next = sanitizePassword(value)
      setConfirmPassword(next)
      setPasswordSuccess(false)
      setPasswordErrors((prev) => ({
        ...prev,
        confirm: validateConfirmPassword(newPassword, next),
      }))
    }
  }

  function handlePasswordSubmit(e) {
    e.preventDefault()
    if (!validatePasswordForm()) return
    setPendingUpdate({
      type: 'password',
      payload: {
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      },
    })
    setLivenessOpen(true)
  }

  async function submitPendingUpdateWithLiveness(sessionId) {
    if (!pendingUpdate) return
    const payload = { ...pendingUpdate.payload, liveness_session_id: sessionId }
    const type = pendingUpdate.type
    setPendingUpdate(null)
    setLivenessOpen(false)
    if (type === 'email') {
      setEmailLoading(true)
      setEmailError('')
    } else if (type === 'phone') {
      setPhoneLoading(true)
      setPhoneError('')
    } else {
      setPasswordLoading(true)
      setPasswordErrors({ current: '', new: '', confirm: '' })
    }
    try {
      const data = await updateProfile(payload)
      setUser(data.user)
      if (type === 'email') {
        setEmailSuccess(true)
      } else if (type === 'phone') {
        if (data.user?.phone_number != null) setPhone(data.user.phone_number)
        else setPhone('')
        setPhoneSuccess(true)
      } else {
        setCurrentPassword('')
        setNewPassword('')
        setConfirmPassword('')
        setPasswordSuccess(true)
      }
    } catch (err) {
      const msg = err?.message || 'Update failed'
      if (type === 'email') setEmailError(msg)
      else if (type === 'phone') setPhoneError(msg)
      else setPasswordErrors((prev) => ({ ...prev, new: msg }))
    } finally {
      setEmailLoading(false)
      setPhoneLoading(false)
      setPasswordLoading(false)
    }
  }

  // —— Photo ———
  async function handlePhotoSelect(e) {
    const file = e.target?.files?.[0]
    e.target.value = ''
    if (!file) return
    if (file.size > MAX_FILE_MB * 1024 * 1024) {
      setPhotoError(`Image must be under ${MAX_FILE_MB} MB.`)
      return
    }
    setPhotoError('')
    setPhotoLoading(true)
    try {
      const data = await uploadProfilePhoto(file)
      setUser(data.user)
    } catch (err) {
      setPhotoError(err?.message || 'Failed to upload photo')
    } finally {
      setPhotoLoading(false)
    }
  }

  async function handleRemovePhoto() {
    setPhotoError('')
    setPhotoLoading(true)
    try {
      const data = await removeProfilePhoto()
      setUser(data.user)
    } catch (err) {
      setPhotoError(err?.message || 'Failed to remove photo')
    } finally {
      setPhotoLoading(false)
    }
  }

  function getPasswordStrength(password) {
    if (!password) return { label: 'Too weak', level: 0, barClass: 'bg-destructive/40', percent: '0%' }
    let score = 0
    if (password.length >= 8) score++
    if (password.length >= 12) score++
    if (/[A-Z]/.test(password) && /[a-z]/.test(password)) score++
    if (/\d/.test(password)) score++
    if (/[^A-Za-z0-9]/.test(password)) score++

    if (score <= 2) {
      return { label: 'Weak', level: 1, barClass: 'bg-red-500/70', percent: '33%' }
    }
    if (score === 3 || score === 4) {
      return { label: 'Good', level: 2, barClass: 'bg-amber-400/80', percent: '66%' }
    }
    return { label: 'Strong', level: 3, barClass: 'bg-emerald-500', percent: '100%' }
  }

  const passwordStrength = getPasswordStrength(newPassword)
  const emailVerifiedAt = user?.email_verified_at ?? null
  const emailVerified = !!emailVerifiedAt
  const lastUpdatedAt = user?.updated_at ? new Date(user.updated_at) : null
  const phoneDigits = String(phone || '').replace(/\D/g, '')
  const localPhoneDigits = phoneDigits.startsWith('63')
    ? phoneDigits.slice(2, 12)
    : phoneDigits.startsWith('0')
      ? phoneDigits.slice(1, 11)
      : phoneDigits.slice(0, 10)
  const localPhoneDisplay = formatLocalPhoneDisplay(localPhoneDigits)
  const isCompanyHead = user?.management_role === 'company_head'
  const profileInfoItems = [
    { label: 'Employee ID', value: user?.employee_code || `ID-${user?.id ?? '—'}`, icon: IdCard },
    { label: 'Department', value: isCompanyHead ? 'Company-wide' : (user?.department || 'No Department Assigned'), icon: Building2 },
    { label: 'Position', value: user?.position || '—', icon: Briefcase },
    { label: 'Branch / Office Location', value: isCompanyHead ? 'All Branches' : (user?.branch_office_location || 'Not assigned'), icon: MapPin },
    { label: 'Employment Type', value: formatEmploymentType(user?.employment_type), icon: Briefcase },
    { label: 'Hire Date', value: user?.hire_date || '—', icon: CalendarDays },
    {
      label: 'Current Shift',
      value:
        user?.schedule_assigned === false
          ? 'No Shift Assigned'
          : user?.working_schedule_name
            ? user?.working_schedule_time
              ? `${user.working_schedule_name} (${formatScheduleLabel12h(user.working_schedule_time)})`
              : user.working_schedule_name
            : '—',
      icon: Clock3,
      isNoSchedule: user?.schedule_assigned === false,
    },
  ]
  const personalInfoItems = [
    { label: 'Date of Birth', value: user?.date_of_birth || '—', icon: Cake },
    { label: 'Gender', value: user?.gender || '—', icon: VenusAndMars },
    { label: 'Civil Status', value: user?.civil_status || '—', icon: Heart },
    { label: 'Nationality', value: user?.nationality || '—', icon: Flag },
    { label: 'Home Address', value: user?.home_address || '—', icon: House, full: true },
  ]

  const employeeTabs = useMemo(
    () => [
      { id: 'personal', label: 'Personal' },
      { id: 'employment', label: 'Employment' },
      { id: 'compensation', label: 'Compensation' },
      { id: 'benefits', label: 'Benefits' },
      { id: 'government-ids', label: 'Gov IDs' },
      { id: 'emergency', label: 'Emergency' },
      { id: 'skills', label: 'Skills' },
      { id: 'account', label: 'Employee Account' },
    ],
    []
  )

  return (
    <div className="space-y-6">
      <div>
        <h1 className="hr-page-title">Profile</h1>
        <p className="hr-helper max-w-2xl">Review your employee profile and manage your account settings.</p>
      </div>

      <Card className="border-2 border-primary/20 bg-primary/5 dark:border-border dark:bg-card/80">
        <CardContent className="pt-6">
          <div className="flex flex-col gap-4 @sm:flex-row @sm:items-center">
            <Avatar className="size-20 rounded-2xl border border-border/60">
              <AvatarImage src={photoUrl || undefined} alt="" className="object-cover" />
              <AvatarFallback className="rounded-2xl bg-primary/10 text-lg font-semibold text-primary">{initials}</AvatarFallback>
            </Avatar>
            <div className="space-y-1">
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-xl font-semibold text-foreground">{user?.name || 'Employee'}</p>
                <RoleBadge user={user} size="sm" />
              </div>
              <p className="text-sm text-foreground">{user?.position || 'No position assigned'}</p>
              <p className="text-sm text-muted-foreground">{user?.department || 'No department assigned'}</p>
              <div className="pt-1 text-xs text-muted-foreground space-y-0.5">
                <p>Employee ID: {user?.employee_code || `ID-${user?.id ?? '—'}`}</p>
                <p>
                  Status:{' '}
                  <span
                    className={cn(
                      'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium',
                      user?.is_active
                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30'
                        : 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/30'
                    )}
                  >
                    {user?.is_active ? 'Active' : 'Inactive'}
                  </span>
                </p>
              </div>
            </div>
          </div>
      <div className="mt-4 flex flex-wrap gap-2">
        <Button type="button" variant="outline" size="sm" onClick={() => setActiveTab('account')}>Employee Account</Button>
        <Button type="button" variant="outline" size="sm" onClick={() => setActiveTab('government-ids')}>Government IDs</Button>
        <Button type="button" variant="outline" size="sm" onClick={() => setActiveTab('emergency')}>Emergency Contacts</Button>
      </div>
        </CardContent>
      </Card>
      <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
        <TabsList variant="line" className="mb-4 w-full justify-start gap-3 overflow-x-auto whitespace-nowrap">
          {employeeTabs.map((t) => (
            <TabsTrigger key={t.id} value={t.id} className="data-[state=active]:font-semibold group-data-[variant=line]/tabs-list:data-[state=active]:after:h-1">
              {t.label}
            </TabsTrigger>
          ))}
        </TabsList>

        <TabsContent value="personal">
          <Card className="border border-border/60">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <User className="size-5" />
                Personal Information
              </CardTitle>
              <CardDescription>Read-only. Contact HR to request changes to personal details.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4 @sm:grid-cols-2">
              {personalInfoItems.map((item) => {
                const Icon = item.icon
                return (
                  <div key={item.label} className={cn('rounded-lg border border-border/60 bg-muted/30 p-4', item.full && '@sm:col-span-2')}>
                    <p className="mb-1 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">
                      <Icon className="size-3.5" />
                      {item.label}
                    </p>
                    <p className="font-medium text-foreground">{item.value}</p>
                  </div>
                )
              })}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="employment">
          <Card className="border border-border/60">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <Briefcase className="size-5" />
                Employment Information
              </CardTitle>
              <CardDescription>This mirrors employment fields maintained by Admin.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
              {profileInfoItems.map((item) => {
                const Icon = item.icon
                return (
                  <div key={item.label} className="rounded-lg border border-border/60 bg-muted/30 p-4">
                    <p className="mb-1 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">
                      <Icon className="size-3.5" />
                      {item.label}
                    </p>
                    <p
                      className={cn(
                        'font-medium',
                        item.isNoSchedule ? 'text-amber-700 dark:text-amber-400' : 'text-foreground'
                      )}
                    >
                      {item.value}
                    </p>
                  </div>
                )
              })}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="compensation">
          <Card className="border border-border/60">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <Shield className="size-5" />
                Compensation
              </CardTitle>
              <CardDescription>Compensation details may be restricted.</CardDescription>
            </CardHeader>
            <CardContent className="text-sm text-muted-foreground">
              Compensation details are not available in this employee view.
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="benefits">
          <Card className="border border-border/60">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <ShieldCheck className="size-5" />
                Benefits
              </CardTitle>
              <CardDescription>Benefits are managed by HR and company policy.</CardDescription>
            </CardHeader>
            <CardContent className="text-sm text-muted-foreground">
              Benefits overview is not yet available in the employee view.
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="government-ids">
          <Card className="border border-border/60">
            <CardHeader className="flex flex-row items-start justify-between">
              <div>
                <CardTitle className="flex items-center gap-2 text-lg">
                  <IdCard className="size-5" />
                  Government IDs
                </CardTitle>
                <CardDescription>These values are stored locally on this device.</CardDescription>
              </div>
              <Badge variant="outline" className="inline-flex items-center gap-1 border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300">
                <LockKeyhole className="size-3.5" />
                Sensitive
              </Badge>
            </CardHeader>
            <CardContent className="grid gap-4 @sm:grid-cols-2">
              {[
                { key: 'tin', label: 'TIN', placeholder: '000-000-000-000' },
                { key: 'sss', label: 'SSS', placeholder: '00-0000000-0' },
                { key: 'philhealth', label: 'PhilHealth', placeholder: '00-000000000-0' },
                { key: 'pagibig', label: 'Pag-IBIG', placeholder: '0000-0000-0000' },
              ].map((f) => (
                <div key={f.key} className="space-y-1">
                  <Label>{f.label}</Label>
                  <Input value={govIds[f.key]} onChange={(e) => updateGovId(f.key, e.target.value)} placeholder={f.placeholder} />
                  {govIdErrors[f.key] && <p className="text-xs text-destructive">{govIdErrors[f.key]}</p>}
                </div>
              ))}
              <p className="@sm:col-span-2 text-xs text-muted-foreground">
                Emojis and stylized Unicode text are blocked. Use only digits and hyphens.
              </p>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="emergency">
          <div className="space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h3 className="text-2xl font-semibold">Emergency Contacts</h3>
                <p className="text-sm text-muted-foreground">Manage contacts to be notified in case of an emergency.</p>
              </div>
              <Button type="button" onClick={startAddEmergency}>
                <Phone className="mr-2 size-4" />
                Add New Contact
              </Button>
            </div>

            <div className="grid gap-4 @lg:grid-cols-2">
              <Card className="border border-border/60">
                <CardHeader>
                  <CardTitle className="text-base">Contacts</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  {emergencyContacts.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No emergency contacts added yet.</p>
                  ) : (
                    emergencyContacts.map((c) => (
                      <div key={c.id} className="rounded-lg border border-border/60 p-3">
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <p className="font-semibold">{c.full_name}</p>
                            <p className="text-xs text-muted-foreground">{c.relationship}</p>
                          </div>
                          {c.is_primary && <Badge className="bg-primary text-primary-foreground">Primary</Badge>}
                        </div>
                        <p className="mt-2 text-sm text-muted-foreground">{c.phone_number}</p>
                        <p className="text-sm text-muted-foreground">{c.address}</p>
                        <div className="mt-3 flex gap-2">
                          <Button type="button" variant="outline" size="sm" onClick={() => startEditEmergency(c)}>Edit</Button>
                          <Button type="button" variant="ghost" size="sm" className="text-destructive" onClick={() => removeEmergency(c.id)}>Delete</Button>
                        </div>
                      </div>
                    ))
                  )}
                </CardContent>
              </Card>

              <Card className="border border-border/60">
                <CardHeader>
                  <CardTitle className="text-base">{editingEmergencyId ? 'Edit Contact' : 'New Contact'}</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  <div className="space-y-1">
                    <Label>Full Name</Label>
                    <Input value={emergencyForm.full_name} onChange={(e) => setEmergencyForm((p) => ({ ...p, full_name: e.target.value }))} />
                    {emergencyErrors.full_name && <p className="text-xs text-destructive">{emergencyErrors.full_name}</p>}
                  </div>
                  <div className="space-y-1">
                    <Label>Relationship</Label>
                    <Input value={emergencyForm.relationship} onChange={(e) => setEmergencyForm((p) => ({ ...p, relationship: sanitizeAsciiByRegex(e.target.value, /[A-Za-z ]/, 30) }))} />
                    {emergencyErrors.relationship && <p className="text-xs text-destructive">{emergencyErrors.relationship}</p>}
                  </div>
                  <div className="space-y-1">
                    <Label>Phone Number</Label>
                    <Input value={emergencyForm.phone_number} onChange={(e) => setEmergencyForm((p) => ({ ...p, phone_number: sanitizeAsciiByRegex(e.target.value, /[0-9+()\-.\s]/, 20) }))} />
                    {emergencyErrors.phone_number && <p className="text-xs text-destructive">{emergencyErrors.phone_number}</p>}
                  </div>
                  <div className="space-y-1">
                    <Label>Address</Label>
                    <Input value={emergencyForm.address} onChange={(e) => setEmergencyForm((p) => ({ ...p, address: sanitizeAsciiByRegex(e.target.value, /[A-Za-z0-9'().,\-/ ]/, 80) }))} />
                    {emergencyErrors.address && <p className="text-xs text-destructive">{emergencyErrors.address}</p>}
                  </div>
                  <div className="flex gap-2">
                    <Button type="button" onClick={saveEmergency}>Save Contact</Button>
                    <Button type="button" variant="outline" onClick={startAddEmergency}>Cancel</Button>
                  </div>
                </CardContent>
              </Card>
            </div>
          </div>
        </TabsContent>

        <TabsContent value="skills">
          <Card className="border border-border/60">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <Sparkles className="size-5" />
                Skills & Certifications
              </CardTitle>
              <CardDescription>Stored locally on this device.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="rounded-md border border-border/60 bg-muted/20 p-3">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Skills</p>
                <div className="mb-3 flex flex-wrap gap-2">
                  {skills.map((s) => (
                    <Badge key={s} variant="secondary" className="gap-1">
                      {s}
                      <button type="button" className="ml-1 text-xs text-muted-foreground hover:text-foreground" onClick={() => removeSkill(s)} aria-label={`Remove ${s}`}>×</button>
                    </Badge>
                  ))}
                </div>
                <Input
                  placeholder="Type a skill and press Enter"
                  value={skillInput}
                  onChange={(e) => setSkillInput(e.target.value)}
                  onBlur={handleAddSkill}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault()
                      handleAddSkill()
                    }
                  }}
                />
              </div>

              <div className="rounded-md border border-border/60 p-3">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Certifications</p>
                <input ref={certFileInputRef} type="file" className="hidden" multiple accept=".pdf,.png,.jpg,.jpeg,.doc,.docx" onChange={handleCertFilesSelected} />
                <div className="rounded-xl border border-dashed p-6 text-center">
                  <p className="text-sm text-muted-foreground">Drag & drop certification files here</p>
                  <p className="mt-1 text-xs text-muted-foreground">or upload manually (PDF, PNG, DOCX)</p>
                  <Button type="button" variant="outline" className="mt-3" onClick={() => certFileInputRef.current?.click()}>
                    <FileText className="mr-2 size-4" />
                    Upload Certification
                  </Button>
                </div>
                <div className="mt-3 space-y-2">
                  {certDocs.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No certifications uploaded yet.</p>
                  ) : (
                    certDocs.map((doc) => (
                      <div key={doc.id} className="flex items-center justify-between rounded-md border px-3 py-2">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium">{doc.name}</p>
                          <p className="text-xs text-muted-foreground">{doc.size} bytes</p>
                        </div>
                        <Button type="button" variant="ghost" size="sm" onClick={() => removeCertDoc(doc.id)}>Remove</Button>
                      </div>
                    ))
                  )}
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="account">
          <div className="grid gap-6 @lg:grid-cols-2">
            {/* Profile photo */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-lg">
                  <User className="size-5" />
                  Profile picture
                </CardTitle>
                <CardDescription>Upload a photo (JPEG, PNG, GIF or WebP, max {MAX_FILE_MB} MB).</CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col items-start gap-4">
                <div className="relative">
                  {photoUrl ? (
                    <img
                      src={photoUrl}
                      alt=""
                      className="size-24 rounded-xl border-2 border-border object-cover"
                    />
                  ) : (
                    <Avatar className="size-24 rounded-xl border-2 border-border">
                      <AvatarFallback className="rounded-xl bg-primary/10 text-xl font-semibold text-primary">
                        {initials}
                      </AvatarFallback>
                    </Avatar>
                  )}
                  {photoLoading && (
                    <span className="absolute inset-0 flex items-center justify-center rounded-xl bg-background/80">
                      <Loader2 className="size-8 animate-spin text-primary" />
                    </span>
                  )}
                </div>
                <div className="flex flex-wrap gap-2">
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept={ACCEPT_IMAGE}
                    className="sr-only"
                    onChange={handlePhotoSelect}
                    disabled={photoLoading}
                  />
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => fileInputRef.current?.click()}
                    disabled={photoLoading}
                  >
                    <Camera className="mr-2 size-4" />
                    Upload
                  </Button>
                  {user?.profile_image && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={handleRemovePhoto}
                      disabled={photoLoading}
                      className="text-muted-foreground"
                    >
                      Remove photo
                    </Button>
                  )}
                </div>
                {photoError && (
                  <p className="text-sm text-destructive" role="alert">{photoError}</p>
                )}
              </CardContent>
            </Card>

            {/* Name */}
            <Card>
              <CardHeader>
                <CardTitle className="text-lg">Change Profile Name</CardTitle>
                <CardDescription>Update how your name appears across the system.</CardDescription>
              </CardHeader>
              <CardContent>
                <form onSubmit={handleNameSubmit} className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="profile-name">New name</Label>
                    <Input
                      id="profile-name"
                      value={name}
                      onChange={handleNameChange}
                      onBlur={() => setNameError(validateName(name))}
                      aria-invalid={!!nameError}
                      className={cn('max-w-md', nameError && 'border-destructive')}
                      placeholder="e.g. Juan Dela Cruz"
                    />
                    {nameError && (
                      <p className="text-sm text-destructive" role="alert">{nameError}</p>
                    )}
                    {nameSuccess && (
                      <p className="text-sm text-emerald-600 dark:text-emerald-400" role="status">
                        Name updated successfully.
                      </p>
                    )}
                  </div>
                  <Button
                    type="submit"
                    disabled={nameLoading || name.trim() === (user?.name || '').trim()}
                  >
                    {nameLoading ? (
                      <>
                        <Loader2 className="mr-2 size-4 animate-spin" />
                        Saving…
                      </>
                    ) : (
                      'Update name'
                    )}
                  </Button>
                </form>
              </CardContent>
            </Card>
          </div>
          {/* Keep existing Security/Biometrics/Activity sections below */}
        </TabsContent>
      </Tabs>

      <div className="grid gap-6 @lg:grid-cols-2">
        {/* Profile photo */}
        {activeTab === 'profile' && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-lg">
              <User className="size-5" />
              Profile picture
            </CardTitle>
            <CardDescription>Upload a photo (JPEG, PNG, GIF or WebP, max {MAX_FILE_MB} MB).</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col items-start gap-4">
            <div className="relative">
              {photoUrl ? (
                <img
                  src={photoUrl}  
                  alt=""
                  className="size-24 rounded-xl border-2 border-border object-cover"
                />
              ) : (
                <Avatar className="size-24 rounded-xl border-2 border-border">
                  <AvatarFallback className="rounded-xl bg-primary/10 text-xl font-semibold text-primary">
                    {initials}
                  </AvatarFallback>
                </Avatar>
              )}
              {photoLoading && (
                <span className="absolute inset-0 flex items-center justify-center rounded-xl bg-background/80">
                  <Loader2 className="size-8 animate-spin text-primary" />
                </span>
              )}
            </div>
            <div className="flex flex-wrap gap-2">
              <input
                ref={fileInputRef}
                type="file"
                accept={ACCEPT_IMAGE}
                className="sr-only"
                onChange={handlePhotoSelect}
                disabled={photoLoading}
              />
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => fileInputRef.current?.click()}
                disabled={photoLoading}
              >
                <Camera className="mr-2 size-4" />
                Upload
              </Button>
              {user?.profile_image && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={handleRemovePhoto}
                  disabled={photoLoading}
                  className="text-muted-foreground"
                >
                  Remove photo
                </Button>
              )}
            </div>
            {photoError && (
              <p className="text-sm text-destructive" role="alert">{photoError}</p>
            )}
          </CardContent>
        </Card>
        )}

        {/* Name */}
        {activeTab === 'profile' && (
        <Card>
          <CardHeader>
            <CardTitle className="text-lg">Change Profile Name</CardTitle>
            <CardDescription>Update how your name appears across the system.</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleNameSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="profile-name">New name</Label>
                <Input
                  id="profile-name"
                  value={name}
                  onChange={handleNameChange}
                  onBlur={() => setNameError(validateName(name))}
                  aria-invalid={!!nameError}
                  className={cn('max-w-md', nameError && 'border-destructive')}
                  placeholder="e.g. Juan Dela Cruz"
                />
                {nameError && (
                  <p className="text-sm text-destructive" role="alert">{nameError}</p>
                )}
                {nameSuccess && (
                  <p className="text-sm text-emerald-600 dark:text-emerald-400" role="status">
                    Name updated successfully.
                  </p>
                )}
              </div>
              <Button
                type="submit"
                disabled={nameLoading || name.trim() === (user?.name || '').trim()}
                className="h-10 rounded-lg px-5 shadow-sm transition-transform duration-150 hover:-translate-y-0.5 hover:shadow-md"
              >
                {nameLoading ? (
                  <>
                    <Loader2 className="mr-2 size-4 animate-spin" />
                    Saving…
                  </>
                ) : (
                  'Update name'
                )}
              </Button>
            </form>
          </CardContent>
        </Card>
        )}

        {/* Email */}
        {activeTab === 'account' && (
        <Card>
          <CardHeader>
            <CardTitle className="text-lg">Change Email Address</CardTitle>
            <CardDescription>Update the email you use to sign in.</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              <div className="rounded-lg border border-border/60 bg-muted/40 px-3 py-2.5 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="space-y-0.5">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">
                      Current email
                    </p>
                    <p className="font-medium text-foreground">{user?.email ?? '—'}</p>
                  </div>
                  <div className="flex flex-col items-end gap-1 text-xs text-muted-foreground">
                    <span
                      className={cn(
                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
                        emailVerified
                          ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border border-emerald-500/40'
                          : 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/40'
                      )}
                    >
                      <span
                        className={cn(
                          'size-1.5 rounded-full',
                          emailVerified ? 'bg-emerald-500' : 'bg-amber-400'
                        )}
                      />
                      {emailVerified ? 'Status: Verified' : 'Status: Not Verified'}
                    </span>
                    {lastUpdatedAt && (
                      <span className="text-[11px]">
                        Last updated:{' '}
                        {lastUpdatedAt.toLocaleDateString(undefined, {
                          year: 'numeric',
                          month: 'short',
                          day: 'numeric',
                        })}
                      </span>
                    )}
                    {!emailVerified && (
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-7 text-[11px]"
                        onClick={() => setEmailError('Email verification code flow is not yet configured on this server.')}
                      >
                        Verify Email
                      </Button>
                    )}
                  </div>
                </div>
              </div>
              <form onSubmit={handleEmailSubmit} className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="profile-email">New email</Label>
                  <Input
                    id="profile-email"
                    type="email"
                    autoComplete="email"
                    value={email}
                    onChange={handleEmailChange}
                    onBlur={() => setEmailError(validateEmail(email))}
                    aria-invalid={!!emailError}
                    className={cn('max-w-md', emailError && 'border-destructive')}
                    placeholder="you@company.com"
                  />
                  {emailError && (
                    <p className="text-sm text-destructive" role="alert">{emailError}</p>
                  )}
                  {emailSuccess && (
                    <p className="text-sm text-emerald-600 dark:text-emerald-400" role="status">
                      Email updated successfully.
                    </p>
                  )}
                </div>
                <Button
                  type="submit"
                  disabled={emailLoading || email === user?.email}
                  className="h-10 rounded-lg px-5 shadow-sm transition-transform duration-150 hover:-translate-y-0.5 hover:shadow-md"
                >
                  {emailLoading ? (
                    <>
                      <Loader2 className="mr-2 size-4 animate-spin" />
                      Saving…
                    </>
                  ) : (
                    'Update email'
                  )}
                </Button>
              </form>
            </div>
          </CardContent>
        </Card>
        )}
      </div>

      {/* Registered face (Employee only) */}
      {activeTab === 'account' && user?.role === 'employee' && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-lg">
              <ScanFace className="size-5" />
              Registered Face
            </CardTitle>
            <CardDescription>
              Your face image used for facial recognition attendance. Register or update in the My QR page.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {faceLoading ? (
              <div className="flex items-center justify-center py-12">
                <Loader2 className="size-8 animate-spin text-muted-foreground" />
              </div>
            ) : hasFace && faceImage ? (
              <div className="flex flex-col items-center gap-4">
                <div className="rounded-lg border-2 border-border bg-muted/30 p-4">
                  <img
                    src={faceImage}
                    alt="Your registered face"
                    className="max-h-48 w-auto rounded-lg object-contain"
                  />
                </div>
                <p className="text-sm text-muted-foreground">Face Recognition Enabled</p>
                <p className="text-xs text-muted-foreground">Used for biometric attendance</p>
                <div className="flex flex-wrap gap-2">
                  <Button type="button" size="sm" variant="outline" onClick={() => navigate('/employee/qr')}>
                    Update Face
                  </Button>
                  <Button type="button" size="sm" variant="outline" onClick={() => navigate('/employee/qr')}>
                    Remove Face
                  </Button>
                </div>
              </div>
            ) : (
              <div className="py-6 text-center">
                <p className="text-sm text-muted-foreground">No face registered.</p>
                <Button type="button" size="sm" variant="outline" className="mt-3" onClick={() => navigate('/employee/qr')}>
                  Register Face
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* Mobile number */}
      {activeTab === 'account' && (
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Mobile number</CardTitle>
          <CardDescription>Used for SMS notifications.</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handlePhoneSubmit} className="max-w-md space-y-4">
            <div className="space-y-2">
              <Label htmlFor="profile-phone">Mobile (Philippines)</Label>
              <div className={cn('flex max-w-md items-center overflow-hidden rounded-md border border-input bg-background', phoneError && 'border-destructive')}>
                <span className="border-r border-border px-3 text-sm text-muted-foreground">+63</span>
                <input
                  id="profile-phone"
                  type="tel"
                  autoComplete="tel"
                  value={localPhoneDisplay}
                  onChange={(e) => {
                    const digits = e.target.value.replace(/\D/g, '').slice(0, 10)
                    const full = digits ? `+63${digits}` : ''
                    setPhone(full)
                    setPhoneError(validatePhone(full, false))
                    setPhoneSuccess(false)
                  }}
                  onBlur={() => setPhoneError(validatePhone(phone, false))}
                  aria-invalid={!!phoneError}
                  className="h-10 w-full bg-transparent px-3 text-sm outline-none"
                  placeholder="9XXXXXXXXX"
                />
              </div>
              {phoneError && (
                <p className="text-sm text-destructive" role="alert">{phoneError}</p>
              )}
              {phoneSuccess && (
                <p className="text-sm text-emerald-600 dark:text-emerald-400" role="status">
                  Mobile number updated.
                </p>
              )}
            </div>
            <Button
              type="submit"
              disabled={phoneLoading}
              className="h-10 rounded-lg px-5 shadow-sm transition-transform duration-150 hover:-translate-y-0.5 hover:shadow-md"
            >
              {phoneLoading ? (
                <>
                  <Loader2 className="mr-2 size-4 animate-spin" />
                  Saving…
                </>
              ) : (
                'Update mobile number'
              )}
            </Button>
          </form>
        </CardContent>
      </Card>
      )}

      {/* Change password */}
      {activeTab === 'account' && (
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Change password</CardTitle>
          <CardDescription>Set a new password. You must enter your current password.</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handlePasswordSubmit} className="max-w-md space-y-4">
            <div className="space-y-2">
              <Label htmlFor="profile-current-password">Current password</Label>
              <div className="relative">
                <Input
                  id="profile-current-password"
                  type={showCurrentPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  value={currentPassword}
                  onChange={(e) => handlePasswordChange('current', e.target.value)}
                  className={cn('pr-10', passwordErrors.current && 'border-destructive')}
                  placeholder="••••••••"
                />
                <button
                  type="button"
                  onClick={() => setShowCurrentPassword((v) => !v)}
                  className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground"
                  aria-label={showCurrentPassword ? 'Hide current password' : 'Show current password'}
                >
                  {showCurrentPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                </button>
              </div>
              {passwordErrors.current && (
                <p className="text-sm text-destructive" role="alert">{passwordErrors.current}</p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="profile-new-password">New password</Label>
              <div className="relative">
                <Input
                  id="profile-new-password"
                  type={showNewPassword ? 'text' : 'password'}
                  autoComplete="new-password"
                  value={newPassword}
                  onChange={(e) => handlePasswordChange('new', e.target.value)}
                  className={cn('pr-10', passwordErrors.new && 'border-destructive')}
                  placeholder="At least 8 characters, letter and number"
                  aria-invalid={!!passwordErrors.new}
                />
                <button
                  type="button"
                  onClick={() => setShowNewPassword((v) => !v)}
                  className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground"
                  aria-label={showNewPassword ? 'Hide new password' : 'Show new password'}
                >
                  {showNewPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                </button>
              </div>
              {passwordErrors.new && (
                <p className="text-sm text-destructive" role="alert">{passwordErrors.new}</p>
              )}
              {newPassword && (
                <div className="space-y-1 pt-1">
                  <div className="flex items-center justify-between text-xs">
                    <span className="text-muted-foreground">Password strength</span>
                    <span
                      className={cn(
                        'font-medium',
                        passwordStrength.level === 1 && 'text-red-600 dark:text-red-400',
                        passwordStrength.level === 2 && 'text-amber-600 dark:text-amber-400',
                        passwordStrength.level === 3 && 'text-emerald-600 dark:text-emerald-400'
                      )}
                    >
                      {passwordStrength.label}
                    </span>
                  </div>
                  <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                      className={cn('h-full rounded-full transition-all duration-200', passwordStrength.barClass)}
                      style={{ width: passwordStrength.percent }}
                    />
                  </div>
                </div>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="profile-confirm-password">Confirm new password</Label>
              <div className="relative">
                <Input
                  id="profile-confirm-password"
                  type={showConfirmPassword ? 'text' : 'password'}
                  autoComplete="new-password"
                  value={confirmPassword}
                  onChange={(e) => handlePasswordChange('confirm', e.target.value)}
                  className={cn('pr-10', passwordErrors.confirm && 'border-destructive')}
                  placeholder="••••••••"
                />
                <button
                  type="button"
                  onClick={() => setShowConfirmPassword((v) => !v)}
                  className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground"
                  aria-label={showConfirmPassword ? 'Hide confirm password' : 'Show confirm password'}
                >
                  {showConfirmPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                </button>
              </div>
              {passwordErrors.confirm && (
                <p className="text-sm text-destructive" role="alert">{passwordErrors.confirm}</p>
              )}
            </div>
            <div className="space-y-1 text-xs text-muted-foreground">
              <p className="font-medium text-foreground/80">Password rules</p>
              <ul className="list-disc space-y-0.5 pl-4">
                <li>At least 8 characters</li>
                <li>Use letters and numbers</li>
              </ul>
            </div>
            {passwordSuccess && (
              <p className="text-sm text-emerald-600 dark:text-emerald-400" role="status">
                Password updated successfully.
              </p>
            )}
            <Button
              type="submit"
              disabled={
                passwordLoading || !currentPassword || !newPassword || !confirmPassword
              }
              className="h-10 rounded-lg px-5 shadow-sm transition-transform duration-150 hover:-translate-y-0.5 hover:shadow-md"
            >
              {passwordLoading ? (
                <>
                  <Loader2 className="mr-2 size-4 animate-spin" />
                  Updating…
                </>
              ) : (
                'Update password'
              )}
            </Button>
          </form>
        </CardContent>
      </Card>
      )}

      {activeTab === 'account' && (
      <div className="grid gap-6 @lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-lg">Security Status</CardTitle>
            <CardDescription>Current account and identity verification status.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            <p className={emailVerified ? 'text-emerald-600' : 'text-amber-600'}>
              {emailVerified ? '🟢 Email verified' : '🔴 Email not verified'}
            </p>
            <p className={phone ? 'text-emerald-600' : 'text-amber-600'}>
              {phone ? '🟢 Phone verified' : '🔴 Phone not set'}
            </p>
            <p className={hasFace ? 'text-emerald-600' : 'text-amber-600'}>
              {hasFace ? '🟢 Face registered' : '🔴 Face not registered'}
            </p>
          </CardContent>
        </Card>
      </div>
      )}

      {activeTab === 'account' && (
        <Card>
          <CardHeader>
            <CardTitle className="text-lg">Activity Log</CardTitle>
            <CardDescription>Recent account activity overview.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-2 text-sm text-muted-foreground">
            <p>🕒 Last login: Not available</p>
            <p>
              📝 Last profile update:{' '}
              {lastUpdatedAt
                ? lastUpdatedAt.toLocaleDateString(undefined, {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                  })
                : '—'}
            </p>
            <p>🔐 Last password change: Not available</p>
          </CardContent>
        </Card>
      )}

      {/* Identity verification (Face Liveness) for sensitive profile updates */}
      <Dialog open={livenessOpen} onOpenChange={(open) => !open && (setLivenessOpen(false), setPendingUpdate(null))}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_LG)}
          aria-describedby="profile-liveness-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={cn(ADMIN_FORM_DIALOG_TITLE_CLASS, 'flex items-center gap-2')}>
                <ShieldCheck className="size-5" />
                Verify your identity
              </DialogTitle>
              <p id="profile-liveness-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Changing your {pendingUpdate?.type === 'email' ? 'email' : pendingUpdate?.type === 'phone' ? 'phone number' : 'password'} requires identity verification. Complete the face liveness check to continue.
              </p>
            </DialogHeader>
          </div>
          <div className={ADMIN_FORM_DIALOG_BODY_CLASS}>
            <FaceRekognitionLiveness
              onVerified={submitPendingUpdateWithLiveness}
              onSuccess={() => setLivenessOpen(false)}
              hideInstruction
              instructionText="Complete the face liveness check to verify your identity."
            />
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button variant="outline" onClick={() => { setLivenessOpen(false); setPendingUpdate(null); }}>
              Cancel
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
