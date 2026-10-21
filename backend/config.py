"""Configuration from environment variables, plus the production safety checks.

Every setting the app reads is listed in ENV_VARS (and documented in the README and deploy/env.example).
"""
import os
import secrets

ENV_VARS = {
    # name: (default, description)
    "LMD_ENV": ("development", "development | staging | production. production refuses unsafe settings."),
    "LMD_SECRET_KEY": ("", "Long random string that signs sessions. Required in staging/production."),
    "LMD_SECURE_COOKIES": ("0", "1 = cookies only over HTTPS. Required in production."),
    "LMD_BASE_URL": ("http://localhost:4321", "Public address used in email links, e.g. https://lmdglobalfinltd.com"),
    "LMD_INSTANCE_DIR": ("<project>/instance", "Private data folder: database, uploads, generated documents, outbox."),
    "LMD_TRUST_PROXY": ("0", "Number of reverse proxies in front of the app (1 behind nginx)."),
    "LMD_SESSION_IDLE_MINUTES": ("30", "Sign out after this many minutes without activity."),
    "LMD_SESSION_HOURS": ("8", "Absolute session lifetime."),
    "LMD_REQUIRE_STAFF_MFA": ("0", "1 = staff must set up an authenticator app before using the admin area."),
    "LMD_MAX_UPLOAD_MB": ("10", "Largest accepted upload."),
    "LMD_SMTP_HOST": ("", "Transactional SMTP server. Empty = write emails to instance/outbox/ instead."),
    "LMD_SMTP_PORT": ("587", "587 (STARTTLS) or 465 (TLS)."),
    "LMD_SMTP_USER": ("", "SMTP username."),
    "LMD_SMTP_PASSWORD": ("", "SMTP password. Never commit it."),
    "LMD_MAIL_FROM": ("LMD Finance <no-reply@lmdglobalfinltd.com>", "Sender for transactional email."),
    "LMD_MAIL_REPLY_TO": ("", "Reply-To for transactional email, e.g. a monitored support inbox."),
    "LMD_STAFF_EMAILS": ("", "Comma-separated staff notification addresses. Default: every staff account."),
    "LMD_MAIL_ALLOWED_DOMAINS": ("", "Staging safety net: only these recipient domains get real email; others go to the outbox."),
    "LMD_MARKETING_ENABLED": ("0", "1 = allow live marketing campaigns (after the compliance checklist is done)."),
    "LMD_MARKETING_SMTP_HOST": ("", "Separate SMTP server for marketing. Empty = marketing goes to the outbox."),
    "LMD_MARKETING_SMTP_PORT": ("587", "Marketing SMTP port."),
    "LMD_MARKETING_SMTP_USER": ("", "Marketing SMTP username."),
    "LMD_MARKETING_SMTP_PASSWORD": ("", "Marketing SMTP password."),
    "LMD_MARKETING_FROM": ("LMD Finance <news@lmdglobalfinltd.com>", "Sender for marketing email."),
    "LMD_MARKETING_RATE_PER_MINUTE": ("60", "Maximum marketing emails sent per minute."),
    "LMD_AI_API_KEY": ("", "Anthropic API key for the optional AI assistant. Empty = AI features off."),
    "LMD_AI_MODEL": ("claude-opus-5-5", "Claude model used by the assistant."),
    "LMD_AI_STAFF_FEATURES": ("0", "1 = staff may send (redacted) enquiry text to the AI provider. Needs a data-processing agreement first."),
    # Multi-tenancy: which organisation a request belongs to is decided by its host name.
    "LMD_PLATFORM_HOST": ("platform.localhost", "Host name of the platform-owner console, e.g. console.yourplatform.com"),
    "LMD_TENANT_BASE_DOMAIN": ("localhost", "Organisations get <slug>.<this domain> automatically, e.g. acme.yourplatform.com"),
    "LMD_DEFAULT_ORG": ("lmd", "Organisation slug for hosts that match nothing else (development). Empty in production = 404."),
    "LMD_STATIC_SITE_ORG": ("lmd", "Organisation that owns the static marketing site in public/."),
}


def env(name):
    default = ENV_VARS[name][0]
    return os.environ.get(name, "" if default.startswith("<") else default)


def flag(name):
    return env(name).strip() == "1"


def secret_key(instance):
    if env("LMD_SECRET_KEY"):
        return env("LMD_SECRET_KEY")
    # Development only: a random key kept in the instance folder.
    path = os.path.join(instance, "secret_key")
    if not os.path.exists(path):
        with open(path, "w", encoding="utf-8") as fh:
            fh.write(secrets.token_hex(32))
    with open(path, encoding="utf-8") as fh:
        return fh.read().strip()


def startup_errors():
    """Settings that make production unsafe. The app refuses to start in production if any are present."""
    if env("LMD_ENV") not in ("production", "staging"):
        return []
    errors = []
    if len(env("LMD_SECRET_KEY")) < 32:
        errors.append("LMD_SECRET_KEY must be set to a random value of at least 32 characters.")
    if not flag("LMD_SECURE_COOKIES"):
        errors.append("LMD_SECURE_COOKIES must be 1 (the site must run over HTTPS).")
    if not env("LMD_BASE_URL").startswith("https://"):
        errors.append("LMD_BASE_URL must be an https:// address.")
    return errors
