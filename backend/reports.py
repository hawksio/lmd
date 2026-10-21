"""Organisation reports. Figures are kept separate on purpose:
requested and approved amounts are not money; disbursed means confirmed payments out; fees "invoiced" are charges
recorded under signed agreements, "collected" only those actually paid; interest income is recognised on a cash
basis (the interest portion of confirmed repayments) unless the organisation's accountant says otherwise.
All amounts are per currency and never mixed.
"""
from collections import defaultdict
from datetime import date

from flask import Blueprint, render_template, request

from .admin import _date_range, csv_response
from .db import query
from .finance import loan_position
from .options import STATUSES
from .pricing import money
from .security import permission_required, staff_required
from .tenancy import org_id

bp = Blueprint("reports", __name__, url_prefix="/admin/reports")


def build(start, end):
    o = org_id()
    rng = (o, start, end)
    apps = query("SELECT * FROM applications WHERE org_id = ? AND status != 'draft' AND date(submitted_at) BETWEEN ? AND ?", rng)
    issued = query("SELECT d.* FROM approval_decisions d JOIN applications a ON a.id = d.application_id WHERE d.org_id = ? "
                   "AND d.status IN ('issued', 'superseded') AND d.issued_at IS NOT NULL AND date(d.issued_at) BETWEEN ? AND ?", rng)
    latest = {}
    for d in issued:
        if d["application_id"] not in latest or d["revision"] > latest[d["application_id"]]["revision"]:
            latest[d["application_id"]] = d
    declined = [a for a in apps if a["status"] == "declined"]
    decided = len(latest) + len(declined)
    by_cur = defaultdict(lambda: defaultdict(int))
    for a in apps:
        by_cur[a["currency"]]["requested"] += (a["amount"] or 0) * 100
    for d in latest.values():
        by_cur[d["currency"]]["approved"] += d["approved_minor"]
        by_cur[d["currency"]]["approved_n"] += 1
    for r in query("SELECT currency, SUM(amount_pence) total, SUM(fees_deducted_minor) fees FROM disbursements WHERE org_id = ? "
                   "AND status = 'confirmed' AND date(confirmed_at) BETWEEN ? AND ? GROUP BY currency", rng):
        by_cur[r["currency"]]["disbursed"] += r["total"]
    for r in query("SELECT currency, SUM(amount_minor) total FROM repayments WHERE org_id = ? AND status = 'confirmed' "
                   "AND received_on BETWEEN ? AND ? GROUP BY currency", rng):
        by_cur[r["currency"]]["repaid"] += r["total"]
    for r in query("SELECT currency, status, SUM(amount_minor) total FROM fee_items WHERE org_id = ? AND date(created_at) BETWEEN ? AND ? "
                   "GROUP BY currency, status", rng):
        if r["status"] != "cancelled":
            by_cur[r["currency"]]["fees_invoiced"] += r["total"]
        by_cur[r["currency"]][f"fees_{r['status']}"] += r["total"]
    for r in query("SELECT currency, SUM(amount_minor) total FROM fee_items WHERE org_id = ? AND status = 'paid' AND date(paid_at) BETWEEN ? AND ? "
                   "GROUP BY currency", rng):
        by_cur[r["currency"]]["fees_collected"] += r["total"]
    # Loan book: outstanding receivables, interest received in the period (cash basis), arrears.
    book = defaultdict(lambda: defaultdict(int))
    for s in query("SELECT application_id, currency FROM repayment_schedules WHERE org_id = ? AND status = 'active'", (o,)):
        pos = loan_position(s["application_id"])
        book[s["currency"]]["outstanding_principal"] += pos["outstanding_principal"]
        book[s["currency"]]["receivable"] += pos["outstanding_total"]
        book[s["currency"]]["overdue"] += pos["overdue_minor"]
        book[s["currency"]]["overdue_loans"] += 1 if pos["overdue_count"] else 0
        book[s["currency"]]["loans"] += 1
    interest = _interest_received(start, end)
    for cur, v in interest.items():
        by_cur[cur]["interest_income"] += v
    # Repayment performance: instalments due in the period, and how many were fully paid.
    due = paid_on_time = 0
    for s in query("SELECT application_id FROM repayment_schedules WHERE org_id = ? AND status = 'active'", (o,)):
        for i in loan_position(s["application_id"])["instalments"]:
            if start <= i["due_date"] <= min(end, date.today().isoformat()):
                due += 1
                paid_on_time += 1 if i["state"] == "paid" else 0
    products = query("SELECT p.name, a.currency, COUNT(a.id) n, SUM(a.amount) requested FROM applications a LEFT JOIN loan_products p "
                     "ON p.id = a.product_id WHERE a.org_id = ? AND a.status != 'draft' AND date(a.submitted_at) BETWEEN ? AND ? "
                     "GROUP BY p.name, a.currency ORDER BY n DESC", rng)
    staff = query("SELECT u.full_name, u.role, (SELECT COUNT(*) FROM applications a WHERE a.assigned_to = u.id AND a.status NOT IN "
                  "('draft', 'declined', 'withdrawn', 'cancelled', 'fully_disbursed')) AS open_assigned, (SELECT COUNT(*) FROM "
                  "approval_decisions d WHERE d.proposed_by = u.id AND date(d.proposed_at) BETWEEN ? AND ?) AS proposed, "
                  "(SELECT COUNT(*) FROM document_reviews r WHERE r.reviewer_id = u.id AND date(r.created_at) BETWEEN ? AND ?) AS reviews "
                  "FROM users u WHERE u.org_id = ? AND u.role != 'client' AND u.is_active = 1 ORDER BY u.full_name",
                  (start, end, start, end, o))
    review_days = [_days(a["submitted_at"], latest[a["id"]]["issued_at"]) for a in apps if a["id"] in latest]
    months = defaultdict(int)
    for a in apps:
        months[(a["submitted_at"] or "")[:7]] += 1
    return {
        "applications": len(apps), "approved": len(latest), "declined": len(declined),
        "approval_rate": round(100 * len(latest) / decided) if decided else None,
        "partial": sum(1 for d in latest.values() if d["decision_type"] == "partial"),
        "full": sum(1 for d in latest.values() if d["decision_type"] == "full"),
        "conditional": sum(1 for d in latest.values() if d["decision_type"] == "conditional"),
        "by_currency": {c: dict(v) for c, v in by_cur.items()},
        "book": {c: dict(v) for c, v in book.items()},
        "avg_review_days": round(sum(review_days) / len(review_days), 1) if review_days else None,
        "verification_backlog": query("SELECT COUNT(*) n FROM documents WHERE org_id = ? AND review_status IN ('submitted', 'under_review')",
                                      (o,), one=True)["n"],
        "instalments_due": due, "instalments_paid": paid_on_time,
        "products": products, "staff": staff, "months": dict(sorted(months.items())),
        "statuses": {STATUSES[r["status"]]: r["n"] for r in query("SELECT status, COUNT(*) n FROM applications WHERE org_id = ? "
                                                                   "AND status != 'draft' GROUP BY status", (o,))},
    }


def _days(a, b):
    from datetime import datetime
    try:
        return (datetime.fromisoformat(b) - datetime.fromisoformat(a)).total_seconds() / 86400
    except (TypeError, ValueError):
        return 0


def _interest_received(start, end):
    """Interest portion of confirmed repayments received in the period: allocation replayed per loan, oldest first."""
    out = defaultdict(int)
    for s in query("SELECT * FROM repayment_schedules WHERE org_id = ? AND status = 'active'", (org_id(),)):
        inst = query("SELECT * FROM instalments WHERE schedule_id = ? ORDER BY number", (s["id"],))
        remaining = [[i["fees_minor"], i["interest_minor"], i["principal_minor"]] for i in inst]
        for r in query("SELECT * FROM repayments WHERE schedule_id = ? AND status = 'confirmed' ORDER BY received_on, id", (s["id"],)):
            pot, interest = r["amount_minor"], 0
            for parts in remaining:
                for idx in range(3):
                    take = min(pot, parts[idx])
                    parts[idx] -= take
                    pot -= take
                    if idx == 1:
                        interest += take
            if start <= r["received_on"] <= end:
                out[s["currency"]] += interest
    return out


@bp.route("/")
@staff_required
@permission_required("view_reports")
def index():
    start, end = _date_range()
    data = build(start, end)
    if request.args.get("export") == "csv":
        rows = []
        for cur, v in data["by_currency"].items():
            for k, n in sorted(v.items()):
                rows.append([cur, k, n, money(n, cur) if not k.endswith("_n") else n])
        for cur, v in data["book"].items():
            for k, n in sorted(v.items()):
                rows.append([cur, f"book_{k}", n, n if k.endswith("loans") else money(n, cur)])
        return csv_response(f"report-{start}-to-{end}.csv", ["currency", "measure", "minor_units_or_count", "formatted"], rows)
    return render_template("admin/reports.html", r=data, start=start, end=end, money=money)
