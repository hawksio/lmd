// Mobile navigation
const toggle = document.querySelector('.nav-toggle');
const nav = document.getElementById('site-nav');
if (toggle && nav) {
  toggle.addEventListener('click', () => {
    const open = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded', String(open));
    nav.classList.toggle('open', open);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && nav.classList.contains('open')) {
      toggle.setAttribute('aria-expanded', 'false');
      nav.classList.remove('open');
      toggle.focus();
    }
  });
}

// Inner pages: photos and cards rise in once as they enter view. The home page has its own
// scroll engine, and reduced motion gets no movement at all.
if (!document.body.classList.contains('home') && 'IntersectionObserver' in window &&
    matchMedia('(prefers-reduced-motion: no-preference)').matches) {
  const targets = document.querySelectorAll('main .section .pic, main .section .card, main .section .role-card, main .steps > .split');
  const io = new IntersectionObserver((entries) => {
    entries.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } });
  }, { rootMargin: '0px 0px -10% 0px' });
  targets.forEach((el) => { el.classList.add('rise'); io.observe(el); });
}

document.querySelectorAll('[data-year]').forEach((el) => { el.textContent = new Date().getFullYear(); });

// Enquiry forms post to the backend (/api/enquiries) with a CSRF token from /api/csrf.
document.querySelectorAll('form[data-enquiry]').forEach((form) => {
  const ok = form.querySelector('[data-success]');
  const fail = form.querySelector('[data-error]');
  const button = form.querySelector('button[type="submit"]');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!form.reportValidity()) return;
    const data = Object.fromEntries(new FormData(form));
    data.consent = form.elements.consent.checked;
    button.disabled = true;
    if (fail) fail.hidden = true;
    try {
      const { token } = await fetch('/api/csrf', { credentials: 'same-origin' }).then((r) => r.json());
      const res = await fetch('/api/enquiries', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify(data),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok || !body.ok) {
        const first = body.errors ? Object.values(body.errors)[0] : body.error;
        throw new Error(first || 'Something went wrong. Please try again or call us.');
      }
      form.querySelectorAll('input, select, textarea, button').forEach((el) => { el.disabled = true; });
      try { sessionStorage.removeItem('lmdPlan'); } catch (err) { /* ignore */ }
      if (ok) { ok.hidden = false; ok.focus(); }
    } catch (err) {
      button.disabled = false;
      if (fail) { fail.textContent = err.message; fail.hidden = false; fail.focus(); }
    }
  });
});
