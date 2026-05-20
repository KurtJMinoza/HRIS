#!/usr/bin/env python3
"""Fix U+FFFD replacement characters in AdminDashboard.jsx."""
import re
from pathlib import Path

path = Path(__file__).resolve().parents[1] / "src/pages/AdminDashboard.jsx"
t = path.read_text(encoding="utf-8", errors="replace")

replacements = [
    ("Unassigned} \ufffd {person", "Unassigned} \u00b7 {person"),
    ('<span className="text-muted-foreground">\ufffd</span>', '<span className="text-muted-foreground">\u00b7</span>'),
    ("contact HR \ufffd your", "contact HR \u2014 your"),
    ("Low attendance \ufffd ", "Low attendance \u2014 "),
    ("High late activity \ufffd ", "High late activity \u2014 "),
    ("Half-Day Summary \ufffd clickable", "Half-Day Summary \u2014 clickable"),
    ("Charts row \ufffd redesigned", "Charts row \u2014 redesigned"),
    ("Weekly Attendance \ufffd vertical", "Weekly Attendance \u2014 vertical"),
    ("Upcoming Holidays \ufffd Holiday Module", "Upcoming Holidays \u2014 Holiday Module"),
    ("Company Attendance Comparison \ufffd horizontal", "Company Attendance Comparison \u2014 horizontal"),
    ("Data tables \ufffd Today", "Data tables \u2014 Today"),
    ("Loading\ufffd", "Loading\u2026"),
    ("Loading birthdays for {browsedBirthdayMonthLabel}\ufffd", "Loading birthdays for {browsedBirthdayMonthLabel}\u2026"),
    ("contract_start_date)} \ufffd End:", "contract_start_date)} \u00b7 End:"),
    ("font-normal\">\ufffd</span>", "font-normal\">\u00b7</span>"),
    ("<span>\ufffd</span>", "<span>\u00b7</span>"),
]
for old, new in replacements:
    t = t.replace(old, new)

t = re.sub(r"employee_name \|\| '\ufffd'", "employee_name || '\u2014'", t)
t = re.sub(r"leave\.employee_name \|\| '\ufffd'", "leave.employee_name || '\u2014'", t)
t = re.sub(r"employee_code \|\| '\ufffd'", "employee_code || '\u2014'", t)
t = re.sub(r"service_length_label \|\| '\ufffd'", "service_length_label || '\u2014'", t)
t = re.sub(r"next_milestone \|\| '\ufffd'", "next_milestone || '\u2014'", t)
t = re.sub(r"recommended_action \|\| '\ufffd'", "recommended_action || '\u2014'", t)
t = re.sub(r"company_name \?\? '\ufffd'", "company_name ?? '\u2014'", t)
t = re.sub(r" \ufffd ", " \u00b7 ", t)
t = re.sub(r"Status'\} \ufffd \$\{", "Status'} \u00b7 ${", t)
t = t.replace("\ufffd", "")

path.write_text(t, encoding="utf-8")
print("remaining U+FFFD:", t.count("\ufffd"))
