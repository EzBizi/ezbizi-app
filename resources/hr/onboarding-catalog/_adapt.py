"""One-shot adapter: A-1 Tools markdown → EzBizi catalog source.

Compile-time markers <<hourly>> <<salary>> <<raise>> never reach a published body.
EzBizi merge tags are the only {{ }} that survive.
"""
from __future__ import annotations

import re
from pathlib import Path

SRC = Path(r"C:\Users\jose\Desktop\Onboarding Contracts")
DST = Path(r"H:\Development\EzBizi\resources\hr\onboarding-catalog")

ROLES = [
    "accounting",
    "customer_relations",
    "dispatch_supervisor",
    "field_inspector",
    "field_repair",
    "field_technician",
    "local_field_manager",
    "marketing",
    "office_employee",
    "project_manager",
    "sales_executive",
]


def strip_comments(text: str) -> str:
    return re.sub(r"<!--.*?-->", "", text, flags=re.S)


def convert_tables(text: str) -> str:
    lines = text.splitlines()
    out: list[str] = []
    i = 0
    while i < len(lines):
        line = lines[i]
        if line.strip().startswith("|"):
            rows: list[str] = []
            while i < len(lines) and lines[i].strip().startswith("|"):
                rows.append(lines[i].strip())
                i += 1
            items = []
            for row in rows:
                cells = [c.strip() for c in row.strip("|").split("|")]
                if not cells or re.fullmatch(r"[-: ]+", cells[0] or ""):
                    continue
                if cells[0].lower() in {"tool", "equipment", "item"}:
                    continue
                if cells[0]:
                    items.append(cells[0])
            if items:
                if out and out[-1] != "":
                    out.append("")
                for item in items:
                    out.append(f"• {item}")
                out.append("")
            continue
        out.append(line)
        i += 1
    return "\n".join(out)


def convert_markdown(text: str) -> str:
    text = convert_tables(text)
    lines = text.splitlines()
    out: list[str] = []
    skipped_title = False
    for line in lines:
        raw = line.rstrip()
        if re.fullmatch(r"-{3,}", raw.strip()):
            if out and out[-1] != "":
                out.append("")
            continue
        heading = re.match(r"^(#{1,6})\s+(.*)$", raw)
        if heading:
            hashes, title = heading.group(1), heading.group(2).strip()
            if not skipped_title and len(hashes) == 1:
                skipped_title = True
                continue
            # Signature-block headings are dropped with the placeholders.
            if re.search(r"SIGNATURE BLOCK", title, re.I):
                continue
            if title.upper() in {
                "EMPLOYER",
                "EMPLOYEE",
                "COMPANY REPRESENTATIVE",
                "CONTRACTOR (FIELD INSPECTOR)",
                "CONTRACTOR",
            }:
                continue
            if out and out[-1] != "":
                out.append("")
            out.append(title)
            out.append("")
            continue
        if re.search(r"SIGNATURE BLOCK", raw, re.I):
            continue
        if raw.startswith("> "):
            inner = raw[2:].strip()
            if re.search(r"SIGNATURE BLOCK", inner, re.I):
                continue
            raw = inner
        if raw.startswith("- "):
            raw = "• " + raw[2:]
        raw = re.sub(r"\*\*(.+?)\*\*", r"\1", raw)
        raw = re.sub(r"__(.+?)__", r"\1", raw)
        out.append(raw)
    text = "\n".join(out)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip() + "\n"


def map_tags(text: str) -> str:
    # Inspector agreement date — never a signing date, never day/month/year2.
    text = re.sub(
        r"on this \{\{day\}\} day of \{\{month\}\}, 20\{\{year2\}\}",
        "on {{document.date}}",
        text,
    )
    text = text.replace("{{company_name}}", "{{organization.name}}")
    text = text.replace("{{company_address}}", "{{employer_address}}")
    # Display name is reserved and derived. Middle name is optional and would
    # print "[Middle name not provided]" for most people, so it stays off the
    # party line.
    text = text.replace("{{name}}", "{{first_name}} {{last_name}}")
    text = text.replace("{{date}}", "{{date_of_hire}}")

    # Compile-time clause markers. A-1 used the same tag as both open and close.
    text = paired_to_section(text, "hourly_only", "hourly")
    text = paired_to_section(text, "raise_only", "raise")
    text = text.replace("{{raise_unit}}", "<<hourly>> per hour<</hourly>>")
    return text


def paired_to_section(text: str, tag: str, section: str) -> str:
    token = "{{" + tag + "}}"
    # A trailing unpaired marker means "this paragraph is the section"
    # (field_repair's same-day overtime clause). Wrap that paragraph first.
    while text.count(token) % 2 == 1:
        last = text.rfind(token)
        before = text[:last]
        para_start = before.rfind("\n\n")
        if para_start < 0:
            para_start = 0
        else:
            para_start += 2
        paragraph = before[para_start:].rstrip()
        text = before[:para_start] + f"<<{section}>>{paragraph}<</{section}>>" + text[last + len(token) :]
    parts = text.split(token)
    if len(parts) == 1:
        return text
    out = [parts[0]]
    for i in range(1, len(parts), 2):
        out.append(f"<<{section}>>{parts[i]}<</{section}>>")
        out.append(parts[i + 1])
    return "".join(out)


def wrap_standard_pay(text: str) -> str:
    text = re.sub(
        r"Hourly Rate: \$\{\{hourly_rate\}\} per hour\n\nSalary: \$\{\{salary_amount\}\} per \{\{salary_period\}\}",
        "<<hourly>>Hourly Rate: ${{hourly_rate}} per hour<</hourly>>\n\n"
        "<<salary>>Salary: ${{salary_amount}} per {{salary_period}}<</salary>>",
        text,
    )
    text = re.sub(
        r"The Employee shall receive a base hourly rate of \$\{\{hourly_rate\}\} per hour, payable in accordance with the Employer's regular payroll schedule \(([^)]+)\)\. All compensation is subject to applicable federal and state tax withholdings and deductions\.\n\n"
        r"The Employee shall receive a fixed salary of \$\{\{salary_amount\}\} per \{\{salary_period\}\}, payable in accordance with the Employer's regular payroll schedule\. All compensation is subject to applicable federal and state tax withholdings and deductions\."
        r"(?:\n\nIf the Employee is absent from work on any scheduled workday, the corresponding daily amount will be deducted from the Employee's salary for that pay period\. The daily rate for purposes of this deduction is \$\{\{daily_deduction\}\} per day\.)?",
        lambda m: (
            "<<hourly>>The Employee shall receive a base hourly rate of ${{hourly_rate}} per hour, payable in accordance with the Employer's regular payroll schedule ("
            + m.group(1)
            + "). All compensation is subject to applicable federal and state tax withholdings and deductions.<</hourly>>\n\n"
            "<<salary>>The Employee shall receive a fixed salary of ${{salary_amount}} per {{salary_period}}, payable in accordance with the Employer's regular payroll schedule. All compensation is subject to applicable federal and state tax withholdings and deductions."
            + (
                "\n\nIf the Employee is absent from work on any scheduled workday, the corresponding daily amount will be deducted from the Employee's salary for that pay period. The daily rate for purposes of this deduction is ${{daily_deduction}} per day."
                if "daily_deduction" in m.group(0)
                else ""
            )
            + "<</salary>>"
        ),
        text,
    )
    text = re.sub(
        r"The Employee shall be compensated on an hourly basis only, at a base hourly rate of \$\{\{hourly_rate\}\} per hour\. All compensation is subject to applicable federal and state tax withholdings and deductions\.\n\n"
        r"The Employee shall receive a fixed salary of \$\{\{salary_amount\}\} per \{\{salary_period\}\}, in lieu of an hourly wage\. All compensation is subject to applicable federal and state tax withholdings and deductions\.",
        "<<hourly>>The Employee shall be compensated on an hourly basis only, at a base hourly rate of ${{hourly_rate}} per hour. All compensation is subject to applicable federal and state tax withholdings and deductions.<</hourly>>\n\n"
        "<<salary>>The Employee shall receive a fixed salary of ${{salary_amount}} per {{salary_period}}, in lieu of an hourly wage. All compensation is subject to applicable federal and state tax withholdings and deductions.<</salary>>",
        text,
    )
    return text


def wrap_technician_pay(text: str) -> str:
    old = (
        "(a) Compensation Method\n\n"
        "The Employee shall be compensated on the following basis: {{comp_method}}.\n\n"
        "Base Hourly Rate: ${{hourly_rate}} per hour.\n\n"
        "Commission Rate: {{commission_pct}}% on total completed sales.\n\n"
        "Tiered Commission — the Employee's commission is based on total weekly completed sales, paid at the single rate for the highest sales bracket reached that week (no commission applies below the first threshold): {{commission_stages}}.\n\n"
        "Fixed Salary: The Employee shall receive a fixed salary of ${{salary_amount}} per {{salary_period}}, in lieu of hourly wages and commissions."
    )
    new = (
        "<<hourly>>(a) Compensation Method\n\n"
        "The Employee shall be compensated on the following basis: {{comp_method}}.\n\n"
        "Base Hourly Rate: ${{hourly_rate}} per hour.\n\n"
        "Commission Rate: {{commission_pct}}% on total completed sales.\n\n"
        "Tiered Commission — the Employee's commission is based on total weekly completed sales, paid at the single rate for the highest sales bracket reached that week (no commission applies below the first threshold): {{commission_stages}}.<</hourly>>\n\n"
        "<<salary>>(a) Base Pay\n\n"
        "The Employee shall receive a fixed salary of ${{salary_amount}} per {{salary_period}}, in lieu of hourly wages and commissions.<</salary>>"
    )
    if old not in text:
        raise SystemExit("field_technician compensation block did not match")
    text = text.replace(old, new, 1)
    calc = (
        "Commission Calculation — Completed Jobs Only\n\n"
        "Commission is calculated and paid only on jobs that have been fully completed. The weekly sales report is based exclusively on done jobs. If a deposit is collected for a job that is not completed within the same week of payment, that deposit will not be included in the weekly sales report until the job is fully completed. Once the job is done, the full sale amount (including any prior deposit) will be reflected in the sales report for the week in which the job is completed."
    )
    if calc not in text:
        raise SystemExit("field_technician commission calculation did not match")
    return text.replace(calc, "<<hourly>>" + calc + "<</hourly>>", 1)


def wrap_technician_training(text: str) -> str:
    return text.replace(
        "Training Compensation: The training period shall be fully compensated at the Employee's agreed-upon hourly rate.",
        "Training Compensation: The training period shall be fully compensated at the Employee's agreed-upon rate of pay.",
    )


def tuck_headings(text: str) -> str:
    return re.sub(
        r"(?m)^(\([a-z]\) [^\n<]+?)<<(hourly|raise|salary)>>\n",
        lambda m: f"<<{m.group(2)}>>{m.group(1)}\n",
        text,
    )


def leftover_a1(text: str) -> list[str]:
    banned = [
        "hourly_only",
        "raise_only",
        "raise_unit",
        "company_name",
        "company_address",
        "{{name}}",
        "{{date}}",
        "{{day}}",
        "{{month}}",
        "{{year2}}",
    ]
    hits = []
    for b in banned:
        if b in text:
            hits.append(b)
    return hits


def main() -> None:
    DST.mkdir(parents=True, exist_ok=True)
    for role in ROLES:
        raw = (SRC / f"{role}.md").read_text(encoding="utf-8")
        text = strip_comments(raw)
        text = convert_markdown(text)
        text = map_tags(text)
        text = wrap_standard_pay(text)
        text = tuck_headings(text)
        text = re.sub(r"_{8,}", "", text)
        if role == "field_technician":
            text = wrap_technician_pay(text)
            text = wrap_technician_training(text)
        if role == "project_manager":
            text = text.replace(
                "In addition to the base hourly rate, the Employee may be eligible",
                "In addition to the base pay, the Employee may be eligible",
            )
        leftover = leftover_a1(text)
        if leftover:
            raise SystemExit(f"{role}: leftover A-1 tokens {leftover}")
        (DST / f"{role}.src.txt").write_text(text, encoding="utf-8")
        print(f"{role}: {len(text)} chars, hourly={text.count('<<hourly>>')} salary={text.count('<<salary>>')} raise={text.count('<<raise>>')}")


if __name__ == "__main__":
    main()
