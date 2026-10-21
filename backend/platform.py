"""Platform-owner console (on LMD_PLATFORM_HOST): organisations, plans, subscriptions, invoices, platform settings.

The console works only with organisation-level facts and aggregate counts. It has no route that reads an
organisation's customers, applications, documents or messages. Every action is written to the audit log.

Billing: there is no payment-provider integration. Subscriptions run in a clearly labelled *manual* mode: the owner
issues invoices and records a payment only after it has actually been received, with its reference.
"""
import json
import os
import re
from datetime import date, timedelta

from flask import Blueprint, abort, current_app, flash, g, redirect, render_template, request, url_for

from . import audit, mail, pricing
from .db import execute, now, query, transaction
from .options import CURRENCIES, RULE_KINDS
from .security import current_user, platform_required
from .tenancy import create as create_org

bp = Blueprint("platform", __name__, url_prefix="/platform")
SUB_STATUSES = {"trial": "Trial", "active": "Active", "past_due": "Past due", "cancelled": "Cancelled", "suspended": "Suspended"}


# ---------------------------------------------------------------- helpers used by organisations
def subscription(org_id):
    return query("SELECT s.*, p.name AS plan_name, p.code AS plan_code, p.max_staff, p.max_applications_month, p.max_products, "
                 "p.price_minor, p.currency AS plan_currency, p.billing_period, p.platform_fee_percent FROM subscriptions s "
                 "JOIN plans p ON p.id = s.plan_id WHERE s.org_id = ?", (org_id,), one=True)


def plan_limit(org_id, key):
    s = subscription(org_id)
    if s is None or s["status"] not in ("trial", "active", "past_due"):
        return None
    return s[key]


def usage_warning(org_id):
    limit = plan_limit(org_id, "max_applications_month")
    if not limit:
        return None
    n = query("SELECT COUNT(*) n FROM applications WHERE org_id = ? AND submitted_at >= date('now', 'start of month')",
              (org_id,), one=True)["n"]
    if n >= limit:
        return f"{n} applications this month: at or above your plan's {limit}. New applications are still accepted; consider upgrading."
    return None


def _org(oid):
    o = query("SELECT * FROM organisations WHERE id = ?", (oid,), one=True)
    if o is None:
        abort(404)
    return o


def _as_org(org):
    """Send emails as this organisation (its name and portal address)."""
    g.org = org
    g.pop("site", None)


def _usage(org_id):
    return {
        "staff": query("SELECT COUNT(*) n FROM users WHERE org_id = ? AND role != 'client' AND is_active = 1", (org_id,), one=True)["n"],
        "customers": query("SELECT COUNT(*) n FROM users WHERE org_id = ? AND role = 'client'", (org_id,), one=True)["n"],
        "apps_month": query("SELECT COUNT(*) n FROM applications WHERE org_id = ? AND submitted_at >= date('now', 'start of month')",
                            (org_id,), one=True)["n"],
        "products": query("SELECT COUNT(*) n FROM loan_products WHERE org_id = ?", (org_id,), one=True)["n"],
    }


# ---------------------------------------------------------------- dashboard and reports
@bp.route("/")
@platform_required
def dashboard():
    try:
        start = date.fromisoformat(request.args.get("from", ""))
    except ValueError:
        start = date.today().replace(day=1) - timedelta(days=365)
    try:
        end = date.fromisoformat(request.args.get("to", ""))
    except ValueError:
        end = date.today()
    orgs = {r["status"]: r["n"] for r in query("SELECT status, COUNT(*) n FROM organisations GROUP BY status")}
    subs = {r["status"]: r["n"] for r in query("SELECT status, COUNT(*) n FROM subscriptions GROUP BY status")}
    revenue = query("SELECT currency, SUM(plan_amount_minor + usage_fee_minor) total, COUNT(*) n FROM subscription_invoices "
                    "WHERE status = 'paid' AND date(paid_at) BETWEEN ? AND ? GROUP BY currency", (start.isoformat(), end.isoformat()))
    outstanding = query("SELECT currency, SUM(plan_amount_minor + usage_fee_minor) total, COUNT(*) n FROM subscription_invoices "
                        "WHERE status = 'issued' GROUP BY currency")
    activity = query("SELECT o.id, o.name, o.status, (SELECT COUNT(*) FROM applications a WHERE a.org_id = o.id AND "
                     "a.submitted_at >= date('now', '-30 days')) AS apps_30d, (SELECT MAX(l.created_at) FROM audit_log l WHERE "
                     "l.org_id = o.id) AS last_activity FROM organisations o ORDER BY apps_30d DESC")
    health = {
        "database": query("PRAGMA quick_check", one=True)[0],
        "failed_emails_24h": query("SELECT COUNT(*) n FROM email_log WHERE status = 'failed' AND created_at >= datetime('now', '-1 day')",
                                   one=True)["n"],
        "last_backup": _last_backup(),
        "migrations": query("SELECT MAX(version) v FROM schema_migrations", one=True)["v"],
    }
    return render_template("platform/dashboard.html", orgs=orgs, subs=subs, revenue=revenue, outstanding=outstanding,
                           activity=activity, health=health, start=start.isoformat(), end=end.isoformat(), SUB_STATUSES=SUB_STATUSES)


def _last_backup():
    folder = os.path.join(current_app.instance_path, "backups")
    files = [os.path.join(folder, f) for f in os.listdir(folder) if f.startswith("lmd-backup")] if os.path.isdir(folder) else []
    if not files:
        return None
    from datetime import datetime
    return datetime.fromtimestamp(max(os.path.getmtime(f) for f in files)).strftime("%Y-%m-%d %H:%M")


# ---------------------------------------------------------------- organisations
SLUG_RE = re.compile(r"^[a-z][a-z0-9-]{1,30}$")
RESERVED = {"platform", "www", "admin", "api", "app", "mail", "console"}


@bp.route("/organisations")
@platform_required
def organisations():
    rows = query("SELECT o.*, s.status AS sub_status, p.name AS plan_name FROM organisations o LEFT JOIN subscriptions s ON s.org_id = o.id "
                 "LEFT JOIN plans p ON p.id = s.plan_id ORDER BY o.name")
    rows = [dict(r) | {"usage": _usage(r["id"])} for r in rows]
    return render_template("platform/organisations.html", rows=rows, SUB_STATUSES=SUB_STATUSES)


@bp.route("/organisations/new", methods=["GET", "POST"])
@platform_required
def new_organisation():
    plans = query("SELECT * FROM plans WHERE active = 1 ORDER BY price_minor")
    form, errors = {"currency": "GBP"}, {}
    if request.method == "POST":
        form = {k: request.form.get(k, "").strip() for k in ("name", "slug", "currency", "admin_name", "admin_email", "plan_id")}
        form["slug"] = form["slug"].lower()
        if not 2 <= len(form["name"]) <= 120:
            errors["name"] = "Enter the organisation's name."
        if not SLUG_RE.match(form["slug"]) or form["slug"] in RESERVED:
            errors["slug"] = "2-31 lower-case letters, numbers or hyphens, starting with a letter."
        elif query("SELECT 1 FROM organisations WHERE slug = ?", (form["slug"],), one=True):
            errors["slug"] = "That address is taken."
        if form["currency"] not in CURRENCIES:
            errors["currency"] = "Choose a currency."
        if len(form["admin_name"]) < 2 or "@" not in form["admin_email"]:
            errors["admin_email"] = "Enter the first administrator's name and email."
        plan = next((p for p in plans if str(p["id"]) == form["plan_id"]), None)
        if plan is None:
            errors["plan_id"] = "Choose a plan (create one under Plans first)."
        if not errors:
            with transaction():
                oid = create_org(form["slug"], form["name"], form["currency"])
                today = date.today()
                status = "trial" if plan["trial_days"] else "active"
                execute("INSERT INTO subscriptions (org_id, plan_id, status, billing_mode, trial_ends_on, current_period_start, "
                        "current_period_end, created_at, updated_at) VALUES (?,?,?,'manual',?,?,?,?,?)",
                        (oid, plan["id"], status, (today + timedelta(days=plan["trial_days"])).isoformat() if plan["trial_days"] else None,
                         today.isoformat(), _period_end(today, plan["billing_period"]).isoformat(), now(), now()))
                audit.log("platform.org_created", "organisation", oid, slug=form["slug"], plan=plan["code"])
            org = _org(oid)
            _as_org(org)
            from .admin import invite_staff
            invite_staff(oid, form["admin_email"].lower(), form["admin_name"], "admin")
            g.org = None
            flash(f"Organisation created. {form['admin_email']} has been emailed a link to set up the administrator account.", "success")
            return redirect(url_for("platform.organisation", oid=oid))
    return render_template("platform/organisation_form.html", form=form, errors=errors, plans=plans, CURRENCIES=CURRENCIES,
                           base=current_app.config["TENANT_BASE_DOMAIN"])


def _period_end(start, period):
    if period == "year":
        try:
            return start.replace(year=start.year + 1) - timedelta(days=1)
        except ValueError:
            return start + timedelta(days=364)
    month = start.month % 12 + 1
    year = start.year + (1 if start.month == 12 else 0)
    try:
        return start.replace(year=year, month=month) - timedelta(days=1)
    except ValueError:
        return date(year, month, 1) + timedelta(days=27)


@bp.route("/organisations/<int:oid>", methods=["GET", "POST"])
@platform_required
def organisation(oid):
    org = _org(oid)
    if request.method == "POST":
        action = request.form.get("action")
        if action in ("suspend", "activate"):
            if not request.form.get("confirm"):
                flash("Tick the box to confirm.", "error")
            else:
                execute("UPDATE organisations SET status = ? WHERE id = ?", ("suspended" if action == "suspend" else "active", oid))
                audit.log(f"platform.org_{action}d", "organisation", oid, actor=current_user())
                flash("Organisation updated.", "success")
        elif action == "add_domain":
            host = request.form.get("host", "").strip().lower()
            if not re.fullmatch(r"[a-z0-9.-]{4,253}", host) or "." not in host or host == current_app.config["PLATFORM_HOST"]:
                flash("Enter a host name such as portal.example.com.", "error")
            elif query("SELECT 1 FROM organisation_domains WHERE host = ?", (host,), one=True):
                flash("That host is already in use.", "error")
            else:
                execute("INSERT INTO organisation_domains (host, org_id, is_primary) VALUES (?,?,?)",
                        (host, oid, 0 if query("SELECT 1 FROM organisation_domains WHERE org_id = ?", (oid,), one=True) else 1))
                audit.log("platform.domain_added", "organisation", oid, host=host)
                flash("Domain added. Point its DNS at the server and add it to the TLS certificate before use.", "success")
        elif action == "remove_domain":
            execute("DELETE FROM organisation_domains WHERE host = ? AND org_id = ?", (request.form.get("host", ""), oid))
            audit.log("platform.domain_removed", "organisation", oid, host=request.form.get("host", ""))
        elif action == "subscription":
            _update_subscription(oid)
        return redirect(url_for("platform.organisation", oid=oid))
    sub = subscription(oid)
    invoices = query("SELECT * FROM subscription_invoices WHERE org_id = ? ORDER BY period_start DESC", (oid,))
    domains = query("SELECT * FROM organisation_domains WHERE org_id = ? ORDER BY is_primary DESC, host", (oid,))
    plans = query("SELECT * FROM plans ORDER BY price_minor")
    admins = query("SELECT full_name, email, last_login_at, totp_enabled_at FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1",
                   (oid,))
    return render_template("platform/organisation.html", the_org=org, sub=sub, invoices=invoices, domains=domains, plans=plans,
                           usage=_usage(oid), admins=admins, SUB_STATUSES=SUB_STATUSES,
                           base=current_app.config["TENANT_BASE_DOMAIN"])


def _update_subscription(oid):
    sub = subscription(oid)
    plan_id = request.form.get("plan_id", type=int)
    status = request.form.get("status", "")
    period_end = request.form.get("current_period_end", "").strip()
    if not query("SELECT 1 FROM plans WHERE id = ?", (plan_id,), one=True) or status not in SUB_STATUSES:
        flash("Choose a plan and status.", "error")
        return
    try:
        period_end = date.fromisoformat(period_end).isoformat() if period_end else None
    except ValueError:
        flash("Enter a valid period end date.", "error")
        return
    if sub is None:
        execute("INSERT INTO subscriptions (org_id, plan_id, status, billing_mode, current_period_start, current_period_end, created_at, updated_at) "
                "VALUES (?,?,?,'manual',?,?,?,?)", (oid, plan_id, status, date.today().isoformat(), period_end, now(), now()))
    else:
        execute("UPDATE subscriptions SET plan_id = ?, status = ?, current_period_end = COALESCE(?, current_period_end), "
                "cancelled_at = CASE WHEN ? = 'cancelled' THEN COALESCE(cancelled_at, ?) ELSE NULL END, updated_at = ? WHERE org_id = ?",
                (plan_id, status, period_end, status, now(), now(), oid))
    audit.log("platform.subscription_updated", "organisation", oid, plan_id=plan_id, status=status)
    flash("Subscription updated.", "success")


@bp.route("/organisations/<int:oid>/invoices", methods=["POST"])
@platform_required
def create_invoice(oid):
    sub = subscription(oid)
    if sub is None or sub["status"] in ("cancelled",):
        abort(400, "No active subscription to invoice.")
    try:
        start = date.fromisoformat(request.form.get("period_start", ""))
    except ValueError:
        abort(400, "Choose the period start.")
    end = _period_end(start, sub["billing_period"])
    # Optional usage fee: a percentage of payments the organisation actually made to its customers in the period.
    usage = query("SELECT COALESCE(SUM(amount_pence), 0) n FROM disbursements WHERE org_id = ? AND status = 'confirmed' AND currency = ? "
                  "AND date(confirmed_at) BETWEEN ? AND ?", (oid, sub["plan_currency"], start.isoformat(), end.isoformat()), one=True)["n"]
    usage_fee = pricing.to_minor(pricing.dec(usage) * pricing.dec(sub["platform_fee_percent"]) / 100)
    number = f"INV-{oid:04d}-{start:%Y%m%d}"
    try:
        execute("INSERT INTO subscription_invoices (org_id, subscription_id, number, period_start, period_end, plan_amount_minor, "
                "usage_fee_minor, currency, status, issued_at, recorded_by) VALUES (?,?,?,?,?,?,?,?, 'issued', ?, ?)",
                (oid, sub["id"], number, start.isoformat(), end.isoformat(), sub["price_minor"], usage_fee, sub["plan_currency"],
                 now(), current_user()["id"]))
    except Exception as exc:
        if "UNIQUE" not in str(exc):
            raise
        flash("An invoice for that period already exists.", "error")
        return redirect(url_for("platform.organisation", oid=oid))
    audit.log("platform.invoice_issued", "organisation", oid, number=number, amount_minor=sub["price_minor"] + usage_fee)
    flash(f"Invoice {number} issued (manual billing: record the payment only once it has been received).", "success")
    return redirect(url_for("platform.organisation", oid=oid))


@bp.route("/invoices/<int:iid>/<action>", methods=["POST"])
@platform_required
def invoice_action(iid, action):
    inv = query("SELECT * FROM subscription_invoices WHERE id = ? AND status = 'issued'", (iid,), one=True)
    if inv is None or action not in ("paid", "void"):
        abort(404)
    if action == "paid":
        ref = request.form.get("payment_reference", "").strip()[:120]
        if not ref or not request.form.get("confirm"):
            flash("Enter the payment reference and confirm the money has been received.", "error")
            return redirect(url_for("platform.organisation", oid=inv["org_id"]))
        execute("UPDATE subscription_invoices SET status = 'paid', paid_at = ?, payment_reference = ?, recorded_by = ? WHERE id = ?",
                (now(), ref, current_user()["id"], iid))
        execute("UPDATE subscriptions SET status = 'active', current_period_end = MAX(COALESCE(current_period_end, ''), ?), updated_at = ? "
                "WHERE id = ? AND status IN ('trial', 'past_due', 'active')", (inv["period_end"], now(), inv["subscription_id"]))
    else:
        execute("UPDATE subscription_invoices SET status = 'void' WHERE id = ?", (iid,))
    audit.log(f"platform.invoice_{action}", "organisation", inv["org_id"], number=inv["number"])
    flash("Invoice updated.", "success")
    return redirect(url_for("platform.organisation", oid=inv["org_id"]))


# ---------------------------------------------------------------- plans and settings
@bp.route("/plans", methods=["GET", "POST"])
@platform_required
def plans():
    errors = {}
    if request.method == "POST":
        f = {k: request.form.get(k, "").strip() for k in ("id", "code", "name", "description", "currency", "billing_period",
                                                          "features", "platform_fee_percent")}
        price = pricing.parse_money(request.form.get("price"))
        ints = {}
        for k in ("trial_days", "max_staff", "max_applications_month", "max_products"):
            raw = request.form.get(k, "").strip()
            ints[k] = int(raw) if raw.isdigit() else None
        ints["trial_days"] = ints["trial_days"] or 0
        if not re.fullmatch(r"[a-z0-9_-]{2,30}", f["code"]):
            errors["code"] = "Use 2-30 lower-case letters, numbers, - or _."
        if len(f["name"]) < 2:
            errors["name"] = "Enter a name."
        if price is None:
            errors["price"] = "Enter the price, e.g. 99.00."
        if f["currency"] not in CURRENCIES or f["billing_period"] not in ("month", "year"):
            errors["currency"] = "Choose a currency and billing period."
        try:
            f["platform_fee_percent"] = pricing.validate_percent(f["platform_fee_percent"] or "0", "10")
        except pricing.PricingError as exc:
            errors["platform_fee_percent"] = str(exc)
        if not errors:
            values = (f["code"], f["name"], f["description"][:500], price, f["currency"], f["billing_period"], ints["trial_days"],
                      ints["max_staff"], ints["max_applications_month"], ints["max_products"], f["platform_fee_percent"],
                      f["features"][:1000], 1 if request.form.get("active") else 0)
            try:
                if f["id"].isdigit():
                    execute("UPDATE plans SET code=?, name=?, description=?, price_minor=?, currency=?, billing_period=?, trial_days=?, "
                            "max_staff=?, max_applications_month=?, max_products=?, platform_fee_percent=?, features=?, active=? WHERE id = ?",
                            values + (int(f["id"]),))
                    pid = int(f["id"])
                else:
                    pid = execute("INSERT INTO plans (code, name, description, price_minor, currency, billing_period, trial_days, max_staff, "
                                  "max_applications_month, max_products, platform_fee_percent, features, active, created_at) "
                                  "VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)", values + (now(),))
            except Exception as exc:
                if "UNIQUE" not in str(exc):
                    raise
                errors["code"] = "That code is already used."
            else:
                audit.log("platform.plan_saved", "plan", pid, code=f["code"], price_minor=price)
                flash("Plan saved. Existing invoices are unchanged.", "success")
                return redirect(url_for("platform.plans"))
    rows = query("SELECT p.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id AND s.status != 'cancelled') AS subs "
                 "FROM plans p ORDER BY p.price_minor")
    return render_template("platform/plans.html", rows=rows, errors=errors, CURRENCIES=CURRENCIES)


@bp.route("/settings", methods=["GET", "POST"])
@platform_required
def platform_settings():
    from .products import platform_caps
    if request.method == "POST":
        caps, errors = {}, []
        for k in RULE_KINDS:
            try:
                caps[k] = pricing.validate_percent(request.form.get(k, "100"), "100")
            except pricing.PricingError as exc:
                errors.append(f"{RULE_KINDS[k]}: {exc}")
        if errors or not request.form.get("confirm"):
            flash("; ".join(errors) or "Tick the box to confirm.", "error")
        else:
            execute("INSERT INTO platform_settings (key, value) VALUES ('percent_caps', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                    (json.dumps(caps),))
            audit.log("platform.caps_updated", "platform", None, caps=json.dumps(caps))
            flash("Platform limits saved. Organisations can set lower limits, never higher.", "success")
        return redirect(url_for("platform.platform_settings"))
    return render_template("platform/settings.html", caps=platform_caps(), RULE_KINDS=RULE_KINDS)


# ---------------------------------------------------------------- scheduled job
def subscription_housekeeping():
    """Daily: mark lapsed trials/periods past due and email renewal reminders 7 days ahead. Returns a summary."""
    today = date.today().isoformat()
    soon = (date.today() + timedelta(days=7)).isoformat()
    lapsed = query("SELECT * FROM subscriptions WHERE (status = 'trial' AND trial_ends_on < ?) OR "
                   "(status = 'active' AND current_period_end < ?)", (today, today))
    for s in lapsed:
        execute("UPDATE subscriptions SET status = 'past_due', updated_at = ? WHERE id = ?", (now(), s["id"]))
        audit.log("platform.subscription_past_due", "organisation", s["org_id"], actor=None)
    reminders = 0
    for s in query("SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE "
                   "(s.status = 'trial' AND s.trial_ends_on = ?) OR (s.status = 'active' AND s.current_period_end = ?)", (soon, soon)):
        org = _org(s["org_id"])
        _as_org(org)
        admins = [u["email"] for u in query("SELECT email FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1", (org["id"],))]
        mail.send(admins, f"Your {s['plan_name']} subscription renews soon", "subscription_reminder", org_name=org["name"], sub=s)
        reminders += 1
    g.org = None
    return {"past_due": len(lapsed), "reminders": reminders}
