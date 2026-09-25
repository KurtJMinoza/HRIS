import PresenceFilingSupportingDocumentsField from './PresenceFilingSupportingDocumentsField'

/** Supporting documents only (no separate reason field). */
export default function PresenceFilingReasonFields({
  attachments,
  onAttachmentsChange,
  fieldClassName,
  selectTriggerClassName,
  required = true,
}) {
  return (
    <PresenceFilingSupportingDocumentsField
      attachments={attachments}
      onAttachmentsChange={onAttachmentsChange}
      fieldClassName={fieldClassName ?? selectTriggerClassName}
      required={required}
    />
  )
}
