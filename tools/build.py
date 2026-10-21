"""Static site builder for LMD Finance Ltd.

Reads src/pages/*.html (front matter + body), wraps them in src/layout.html, fills in company details and
FAQs from the website content, expands the responsive image component, and writes finished pages to public/.

Content comes from content/site.json, overridden by instance/content/published.json once staff publish
changes in /admin/content (publishing there runs this builder automatically). Every value is HTML-escaped;
missing (unverified) details render as visible "[... to be confirmed]" placeholders.

Content tokens in pages and the layout:
    {{site:key}}      the setting, or its placeholder           {{tel:phone}}   a tel: link, or the placeholder
    {{mailto:email}}  a mailto: link, or the placeholder        {{faqs}}        the FAQ list
    {{if:key}}...{{/if:key}}  kept only when the setting has a value (e.g. example_apr)
    {{ifnot:key}}...{{/ifnot:key}}  kept only when it is empty

Image component (the only way photos should be placed on a page):

    <x-img src="consultations/one-to-one" alt="..." sizes="(min-width: 900px) 50vw, 100vw"
           class="extra classes" eager>

expands to a <picture> with AVIF + WebP sources at 960w/1600w, an art-directed mobile crop for
screens <= 640px, a JPG fallback, intrinsic width/height (no layout shift) and lazy loading unless
`eager` is set (use only above the fold).

Usage:  py tools/build.py
"""
import html
import json
import os
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "src"
OUT = ROOT / "public"
CFG = json.loads((ROOT / "tools" / "images.json").read_text(encoding="utf-8"))
IMAGES = {i["file"]: i for i in CFG["images"]}
SITE_URL = os.environ.get("LMD_BASE_URL", "https://lmdglobalfinltd.com").rstrip("/")
NAV = [
    ("home", "index.html", "Home"),
    ("about", "about.html", "About Us"),
    ("solutions", "solutions.html", "Business Finance"),
    ("how", "how-it-works.html", "How It Works"),
    ("faq", "faq.html", "FAQs"),
    ("contact", "contact.html", "Contact Us"),
]
PLACEHOLDERS = {
    "legal_name": "[Legal company name to be confirmed]",
    "trading_name": "[Trading name to be confirmed]",
    "company_number": "[Company number to be confirmed]",
    "registered_office": "[Registered office address to be confirmed]",
    "phone": "[Telephone number to be confirmed]",
    "email": "[Contact email to be confirmed]",
    "opening_hours": "[Opening hours to be confirmed]",
    "regulatory_statement": "[Regulatory status to be confirmed]",
    "ico_registration": "[ICO registration number to be confirmed]",
    "complaints_contact": "[Complaints contact details to be confirmed]",
}

ATTR_RE = re.compile(r'([\w-]+)(?:="([^"]*)")?')


def load_content():
    data = json.loads((ROOT / "content" / "site.json").read_text(encoding="utf-8"))
    instance = Path(os.environ.get("LMD_INSTANCE_DIR", ROOT / "instance"))
    published = instance / "content" / "published.json"
    if published.exists():
        pub = json.loads(published.read_text(encoding="utf-8"))
        data["settings"].update(pub.get("settings", {}))
        data["faqs"] = pub.get("faqs", data["faqs"])
    return data


def picture(attrs: dict, prefix: str) -> str:
    name = attrs["src"]
    if name not in IMAGES:
        raise SystemExit(f"Unknown image '{name}' - add it to tools/images.json")
    if not attrs.get("alt", "").strip() and "decorative" not in attrs:
        raise SystemExit(f"Image '{name}' needs alt text (or mark it decorative)")
    img = IMAGES[name]
    dw, dh = img["desktop"]
    base = f"{prefix}images/{name}"
    sizes = attrs.get("sizes", "100vw")
    eager = "eager" in attrs
    alt = "" if "decorative" in attrs else attrs["alt"]
    cls = ("pic " + attrs.get("class", "")).strip()
    md_h = round(960 * dh / dw)
    loading = 'loading="eager" fetchpriority="high"' if eager else 'loading="lazy"'
    return (
        f'<picture class="{cls}" style="--ar:{dw}/{dh}">'
        f'<source media="(max-width: 640px)" type="image/avif" srcset="{base}-sm.avif">'
        f'<source media="(max-width: 640px)" type="image/webp" srcset="{base}-sm.webp">'
        f'<source type="image/avif" srcset="{base}-md.avif 960w, {base}-lg.avif 1600w" sizes="{sizes}">'
        f'<source type="image/webp" srcset="{base}-md.webp 960w, {base}-lg.webp 1600w" sizes="{sizes}">'
        f'<img src="{base}-md.jpg" width="960" height="{md_h}" alt="{alt}" {loading} decoding="async">'
        f"</picture>"
    )


def expand_images(text: str, prefix: str) -> str:
    def repl(m):
        attrs = {k: (v if v is not None else True) for k, v in ATTR_RE.findall(m.group(1))}
        return picture(attrs, prefix)

    return re.sub(r"<x-img\s+([^>]*?)/?>", repl, text)


def _placeholder(key):
    return f'<span class="placeholder">{html.escape(PLACEHOLDERS.get(key, "[To be confirmed]"))}</span>'


def _faqs(faqs):
    items = "\n".join(
        f'<details class="faq"><summary>{html.escape(f["q"])}</summary>'
        + "".join(f"<p>{html.escape(p)}</p>" for p in f["a"].split("\n") if p.strip())
        + "</details>"
        for f in faqs
    )
    ld = json.dumps({"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [
        {"@type": "Question", "name": f["q"], "acceptedAnswer": {"@type": "Answer", "text": f["a"]}} for f in faqs]})
    ld = ld.replace("<", "\\u003c").replace(">", "\\u003e").replace("&", "\\u0026")  # inert inside <script>
    return f'<div class="faq-list">{items}</div>\n<script type="application/ld+json">{ld}</script>'


def fill_content(text: str, data: dict) -> str:
    s = data["settings"]

    def keep_if(m):
        return m.group(3) if bool(s.get(m.group(2), "").strip()) == (m.group(1) == "if") else ""

    text = re.sub(r"\{\{(if|ifnot):(\w+)\}\}(.*?)\{\{/\1:\2\}\}", keep_if, text, flags=re.S)

    def token(m):
        kind, key = m.group(1), m.group(2)
        value = s.get(key, "").strip()
        if not value:
            return "" if kind == "raw" else _placeholder(key)
        if kind == "tel":
            return f'<a href="tel:{html.escape(re.sub(r"[^+0-9]", "", value))}">{html.escape(value)}</a>'
        if kind == "mailto":
            return f'<a href="mailto:{html.escape(value)}">{html.escape(value)}</a>'
        if kind == "raw":  # attribute values: escaped, no placeholder markup
            return html.escape(value)
        return html.escape(value).replace("\n", "<br>")

    text = re.sub(r"\{\{(site|tel|mailto|raw):(\w+)\}\}", token, text)
    return text.replace("{{faqs}}", _faqs(data["faqs"]))


def parse(path: Path):
    text = path.read_text(encoding="utf-8")
    meta, body = {}, text
    if text.startswith("---"):
        _, fm, body = text.split("---", 2)
        for line in fm.strip().splitlines():
            k, v = line.split(":", 1)
            meta[k.strip()] = v.strip()
    return meta, body


def render_page(rel_name: str, data: dict, root: str | None = None, banner: str = "") -> str:
    """Render one page from src/pages. `root` overrides the relative asset prefix (used by the admin preview)."""
    page = SRC / "pages" / rel_name
    rel = page.relative_to(SRC / "pages")
    prefix = root if root is not None else "../" * (len(rel.parts) - 1)
    meta, body = parse(page)
    if meta.get("layout") == "none":
        out = body
    else:
        layout = (SRC / "layout.html").read_text(encoding="utf-8")
        current = ' aria-current="page"'
        nav = "\n".join(
            f'<li><a href="{prefix}{href}"{current if key == meta.get("nav") else ""}>{label}</a></li>'
            for key, href, label in NAV
        )

        # Optional per-page assets, comma-separated paths relative to public/.
        # styles_before loads ahead of site.css so site.css keeps the last word.
        def tags(key, fmt):
            return "\n".join(fmt.format(prefix + p.strip()) for p in meta.get(key, "").split(",") if p.strip())

        css = '<link rel="stylesheet" href="{}">'
        canonical = f"{SITE_URL}/" + ("" if rel.as_posix() == "index.html" else rel.as_posix())
        announcement = data["settings"].get("announcement", "").strip()
        notices = ""
        if banner:
            notices += f'<p class="site-notice preview">{html.escape(banner)}</p>'
        if announcement:
            notices += f'<p class="site-notice">{html.escape(announcement)}</p>'
        out = (
            layout.replace("{{styles_before}}", tags("styles_before", css))
            .replace("{{styles}}", tags("styles", css))
            .replace("{{scripts}}", tags("scripts", '<script src="{}" defer></script>'))
            .replace("{{title}}", html.escape(meta.get("title", "LMD Finance Ltd")))
            .replace("{{description}}", html.escape(meta.get("description", "")))
            .replace("{{canonical}}", canonical)
            .replace("{{og_image}}", f"{SITE_URL}/images/{meta.get('og_image', 'consultations/one-to-one')}-md.jpg")
            .replace("{{robots}}", meta.get("robots", "index, follow"))
            .replace("{{body_class}}", meta.get("body_class", ""))
            .replace("{{notices}}", notices)
            .replace("{{nav}}", nav)
            .replace("{{content}}", body)
        )
    out = fill_content(out, data)
    return expand_images(out, prefix).replace("{{root}}", prefix).strip() + "\n"


def build(out: Path | str = OUT, content: dict | None = None, quiet: bool = False):
    out = Path(out)
    data = content or load_content()
    pages = sorted((SRC / "pages").rglob("*.html"))
    urls = []
    for page in pages:
        rel = page.relative_to(SRC / "pages")
        html_text = render_page(rel.as_posix(), data)
        dest = out / rel
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(html_text, encoding="utf-8")
        if rel.parts[0] != "emails":
            urls.append(f"{SITE_URL}/" + ("" if rel.as_posix() == "index.html" else rel.as_posix()))
        if not quiet:
            print("built", rel.as_posix())
    (out / "sitemap.xml").write_text(
        '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
        + "".join(f"  <url><loc>{u}</loc></url>\n" for u in urls) + "</urlset>\n", encoding="utf-8")
    (out / "robots.txt").write_text(
        f"User-agent: *\nDisallow: /account/\nDisallow: /admin/\nDisallow: /api/\nDisallow: /emails/\n\nSitemap: {SITE_URL}/sitemap.xml\n",
        encoding="utf-8")
    missing = [label for key, label in PLACEHOLDERS.items() if key not in ("trading_name",) and not data["settings"].get(key)]
    if missing and not quiet:
        print(f"\nNOTE: {len(missing)} company detail(s) still show placeholders. Confirm them in /admin/content before launch.")


if __name__ == "__main__":
    build()
