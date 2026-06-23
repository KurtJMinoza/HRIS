<?php

namespace Database\Seeders;

use App\Models\EmailNotificationSetting;
use App\Models\EmailTemplate;
use App\Support\AgcEmailTemplateBuilder as B;
use Illuminate\Database\Seeder;

class EmailNotificationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $def) {
            $template = EmailTemplate::query()->firstOrNew(['template_key' => $def['key']]);
            $template->fill(
                $template->exists
                    ? [
                        'subject' => $template->subject ?: $def['subject'],
                        'body_html' => $template->body_html ?: $def['body_html'],
                        'body_text' => $template->body_text,
                        'is_active' => $template->is_active ?? true,
                    ]
                    : [
                        'subject' => $def['subject'],
                        'body_html' => $def['body_html'],
                        'body_text' => null,
                        'is_active' => true,
                    ]
            );
            $template->save();

            $queueName = match (true) {
                str_starts_with($def['key'], 'attendance_clock') || $def['key'] === 'attendance_missing_reminder' => 'attendance-emails',
                str_starts_with($def['key'], 'leave_') || str_starts_with($def['key'], 'overtime_') || str_starts_with($def['key'], 'attendance_correction_') => 'approval-emails',
                $def['key'] === 'payroll_finalized' || $def['key'] === 'payslip_available' => 'payroll-emails',
                default => 'emails',
            };

            $setting = EmailNotificationSetting::query()->firstOrNew(['notification_key' => $def['key']]);
            $setting->fill([
                'label' => $def['label'],
                'description' => $def['description'],
                'enabled' => $setting->exists ? (bool) $setting->enabled : true,
                'recipient_type' => $def['recipient_type'],
                'custom_recipient_email' => $setting->custom_recipient_email,
                'template_id' => $template->id,
                'queue_name' => $setting->queue_name ?: $queueName,
                'retry_attempts' => $setting->retry_attempts ?? 3,
            ]);
            $setting->save();
        }
    }

    /**
     * @return list<array{key: string, label: string, description: string, recipient_type: string, subject: string, body_html: string}>
     */
    private function definitions(): array
    {
        return [
            [
                'key' => 'attendance_missing_reminder',
                'label' => 'Missing Clock-In Reminder',
                'description' => 'Sent when an employee has not clocked in 30 minutes past their scheduled start.',
                'recipient_type' => 'employee',
                'subject' => 'Attendance Reminder — {{ date }}',
                'body_html' => B::layout(
                    B::title('Attendance Reminder')
                    .B::greeting()
                    .B::paragraph('This is a reminder that your attendance for today has not yet been recorded.')
                    .B::infoTable([
                        ['label' => 'Date', 'value' => '{{ date }}'],
                        ['label' => 'Scheduled start', 'value' => '{{ scheduled_time }}'],
                    ])
                    .B::paragraph('If you are already at work, please clock in through the HRIS. If you are on approved leave, you may disregard this message.')
                    .B::cta('Record attendance')
                    .B::closing(),
                    'Attendance reminder for {{ date }}'
                ),
            ],
            [
                'key' => 'attendance_clock_in',
                'label' => 'Clock In Confirmation',
                'description' => 'Sent to the employee when they clock in.',
                'recipient_type' => 'employee',
                'subject' => 'Clock-In Confirmation — {{ date }}',
                'body_html' => B::layout(
                    B::title('Clock-In Confirmation')
                    .B::greeting()
                    .B::paragraph('Your clock-in has been recorded successfully.')
                    .B::infoTable([
                        ['label' => 'Date', 'value' => '{{ date }}'],
                        ['label' => 'Time', 'value' => '{{ time }}'],
                    ])
                    .B::cta('View attendance')
                    .B::closing(),
                    'Clock-in confirmation for {{ date }}'
                ),
            ],
            [
                'key' => 'attendance_clock_out',
                'label' => 'Clock Out Confirmation',
                'description' => 'Sent to the employee when they clock out.',
                'recipient_type' => 'employee',
                'subject' => 'Clock-Out Confirmation — {{ date }}',
                'body_html' => B::layout(
                    B::title('Clock-Out Confirmation')
                    .B::greeting()
                    .B::paragraph('Your clock-out has been recorded successfully.')
                    .B::infoTable([
                        ['label' => 'Date', 'value' => '{{ date }}'],
                        ['label' => 'Time', 'value' => '{{ time }}'],
                    ])
                    .B::cta('View attendance')
                    .B::closing(),
                    'Clock-out confirmation for {{ date }}'
                ),
            ],
            [
                'key' => 'leave_needs_approval',
                'label' => 'Leave — Needs Approval',
                'description' => 'Sent to the current approver when a leave request is filed or escalated.',
                'recipient_type' => 'current_approver',
                'subject' => 'Leave Request for Approval — {{ employee_name }}',
                'body_html' => B::layout(
                    B::title('Leave Request for Approval')
                    .B::greetingGeneric()
                    .B::paragraph('A leave request has been submitted and requires your review.')
                    .B::infoTable([
                        ['label' => 'Employee', 'value' => '{{ employee_name }}'],
                        ['label' => 'Leave type', 'value' => '{{ leave_type }}'],
                        ['label' => 'Period', 'value' => '{{ start_date }} to {{ end_date }}'],
                    ])
                    .B::cta('Review request')
                    .B::closing(),
                    'Leave request pending approval'
                ),
            ],
            [
                'key' => 'leave_approved',
                'label' => 'Leave — Approved',
                'description' => 'Sent to the employee when their leave request is approved.',
                'recipient_type' => 'employee',
                'subject' => 'Leave Request Approved',
                'body_html' => B::layout(
                    B::title('Leave Request Approved')
                    .B::greeting()
                    .B::paragraph('Your leave request has been approved.')
                    .B::infoTable([
                        ['label' => 'Leave type', 'value' => '{{ leave_type }}'],
                        ['label' => 'Period', 'value' => '{{ start_date }} to {{ end_date }}'],
                        ['label' => 'Approved by', 'value' => '{{ approver_name }}'],
                    ])
                    .B::cta('View leave record')
                    .B::closing(),
                    'Your leave request has been approved'
                ),
            ],
            [
                'key' => 'leave_rejected',
                'label' => 'Leave — Rejected',
                'description' => 'Sent to the employee when their leave request is rejected.',
                'recipient_type' => 'employee',
                'subject' => 'Leave Request Not Approved',
                'body_html' => B::layout(
                    B::title('Leave Request Not Approved')
                    .B::greeting()
                    .B::paragraph('Your leave request was reviewed and was not approved.')
                    .B::infoTable([
                        ['label' => 'Leave type', 'value' => '{{ leave_type }}'],
                        ['label' => 'Period', 'value' => '{{ start_date }} to {{ end_date }}'],
                        ['label' => 'Reviewed by', 'value' => '{{ approver_name }}'],
                    ])
                    .B::paragraph('For further clarification, please contact your immediate supervisor or the HR department.')
                    .B::cta('View leave record')
                    .B::closing(),
                    'Your leave request was not approved'
                ),
            ],
            [
                'key' => 'overtime_needs_approval',
                'label' => 'Overtime — Needs Approval',
                'description' => 'Sent to the current approver when an overtime request is filed.',
                'recipient_type' => 'current_approver',
                'subject' => 'Overtime Request for Approval — {{ employee_name }}',
                'body_html' => B::layout(
                    B::title('Overtime Request for Approval')
                    .B::greetingGeneric()
                    .B::paragraph('An overtime request has been submitted and requires your review.')
                    .B::infoTable([
                        ['label' => 'Employee', 'value' => '{{ employee_name }}'],
                        ['label' => 'Date', 'value' => '{{ date }}'],
                        ['label' => 'Hours', 'value' => '{{ hours }}'],
                    ])
                    .B::cta('Review request')
                    .B::closing(),
                    'Overtime request pending approval'
                ),
            ],
            [
                'key' => 'overtime_approved',
                'label' => 'Overtime — Approved',
                'description' => 'Sent to the employee when their overtime request is approved.',
                'recipient_type' => 'employee',
                'subject' => 'Overtime Request Approved',
                'body_html' => B::layout(
                    B::title('Overtime Request Approved')
                    .B::greeting()
                    .B::paragraph('Your overtime request has been approved.')
                    .B::infoTable([
                        ['label' => 'Date', 'value' => '{{ date }}'],
                        ['label' => 'Hours', 'value' => '{{ hours }}'],
                        ['label' => 'Approved by', 'value' => '{{ approver_name }}'],
                    ])
                    .B::cta('View overtime record')
                    .B::closing(),
                    'Your overtime request has been approved'
                ),
            ],
            [
                'key' => 'overtime_rejected',
                'label' => 'Overtime — Rejected',
                'description' => 'Sent to the employee when their overtime request is rejected.',
                'recipient_type' => 'employee',
                'subject' => 'Overtime Request Not Approved',
                'body_html' => B::layout(
                    B::title('Overtime Request Not Approved')
                    .B::greeting()
                    .B::paragraph('Your overtime request was reviewed and was not approved.')
                    .B::infoTable([
                        ['label' => 'Date', 'value' => '{{ date }}'],
                        ['label' => 'Hours', 'value' => '{{ hours }}'],
                        ['label' => 'Reviewed by', 'value' => '{{ approver_name }}'],
                    ])
                    .B::paragraph('For further clarification, please contact your immediate supervisor or the HR department.')
                    .B::cta('View overtime record')
                    .B::closing(),
                    'Your overtime request was not approved'
                ),
            ],
            [
                'key' => 'attendance_correction_needs_approval',
                'label' => 'Attendance Correction — Needs Approval',
                'description' => 'Sent to the current approver when an attendance correction is filed.',
                'recipient_type' => 'current_approver',
                'subject' => 'Attendance Correction for Approval — {{ employee_name }}',
                'body_html' => B::layout(
                    B::title('Attendance Correction for Approval')
                    .B::greetingGeneric()
                    .B::paragraph('An attendance correction request has been submitted and requires your review.')
                    .B::infoTable([
                        ['label' => 'Employee', 'value' => '{{ employee_name }}'],
                        ['label' => 'Date', 'value' => '{{ date }}'],
                        ['label' => 'Request type', 'value' => '{{ request_type }}'],
                    ])
                    .B::cta('Review request')
                    .B::closing(),
                    'Attendance correction pending approval'
                ),
            ],
            [
                'key' => 'attendance_correction_approved',
                'label' => 'Attendance Correction — Approved',
                'description' => 'Sent to the employee when their attendance correction is approved.',
                'recipient_type' => 'employee',
                'subject' => 'Attendance Correction Approved',
                'body_html' => B::layout(
                    B::title('Attendance Correction Approved')
                    .B::greeting()
                    .B::paragraph('Your attendance correction request has been approved and your record has been updated.')
                    .B::infoTable([
                        ['label' => 'Date', 'value' => '{{ date }}'],
                        ['label' => 'Approved by', 'value' => '{{ approver_name }}'],
                    ])
                    .B::cta('View attendance')
                    .B::closing(),
                    'Your attendance correction has been approved'
                ),
            ],
            [
                'key' => 'attendance_correction_rejected',
                'label' => 'Attendance Correction — Rejected',
                'description' => 'Sent to the employee when their attendance correction is rejected.',
                'recipient_type' => 'employee',
                'subject' => 'Attendance Correction Not Approved',
                'body_html' => B::layout(
                    B::title('Attendance Correction Not Approved')
                    .B::greeting()
                    .B::paragraph('Your attendance correction request was reviewed and was not approved.')
                    .B::infoTable([
                        ['label' => 'Date', 'value' => '{{ date }}'],
                        ['label' => 'Reviewed by', 'value' => '{{ approver_name }}'],
                    ])
                    .B::paragraph('For further clarification, please contact your immediate supervisor or the HR department.')
                    .B::cta('View attendance')
                    .B::closing(),
                    'Your attendance correction was not approved'
                ),
            ],
            [
                'key' => 'payroll_finalized',
                'label' => 'Payroll Finalized',
                'description' => 'Sent to the employee when payroll for their period has been finalized.',
                'recipient_type' => 'employee',
                'subject' => 'Payroll Finalized — {{ pay_period }}',
                'body_html' => B::layout(
                    B::title('Payroll Finalized')
                    .B::greeting()
                    .B::paragraph('Payroll for the period below has been finalized. Your payslip will be published in the HRIS once processing is complete.')
                    .B::infoTable([
                        ['label' => 'Pay period', 'value' => '{{ pay_period }}'],
                    ])
                    .B::cta('View payslips')
                    .B::closing(),
                    'Payroll finalized for {{ pay_period }}'
                ),
            ],
            [
                'key' => 'payslip_available',
                'label' => 'Payslip Available',
                'description' => 'Sent to the employee when their payslip is ready for viewing.',
                'recipient_type' => 'employee',
                'subject' => 'Payslip Available — {{ pay_period }}',
                'body_html' => B::layout(
                    B::title('Payslip Available')
                    .B::greeting()
                    .B::paragraph('Your payslip is now available for viewing and download.')
                    .B::infoTable([
                        ['label' => 'Pay period', 'value' => '{{ pay_period }}'],
                        ['label' => 'Employee', 'value' => '{{ employee_name }}'],
                    ])
                    .B::cta('View payslip')
                    .B::closing(),
                    'Your payslip is available for {{ pay_period }}'
                ),
            ],
        ];
    }
}
