// Client portal and staff area behaviour. No inline scripts: the Content-Security-Policy forbids them.

// Ask before destructive actions: <form data-confirm="Delete this draft?">
document.querySelectorAll('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (e) => {
    if (!window.confirm(form.dataset.confirm)) e.preventDefault();
  });
});

// Stop double submissions (the server also de-duplicates new applications).
document.querySelectorAll('form[data-once]').forEach((form) => {
  form.addEventListener('submit', (e) => {
    if (form.dataset.sent) { e.preventDefault(); return; }
    form.dataset.sent = '1';
    const clicked = e.submitter;
    // Keep the clicked button's name/value (e.g. action=submit) after disabling it.
    if (clicked && clicked.name) {
      const keep = document.createElement('input');
      keep.type = 'hidden'; keep.name = clicked.name; keep.value = clicked.value;
      form.appendChild(keep);
    }
    setTimeout(() => form.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; }), 0);
  });
});

// Choosing a loan product redisplays the form with that product's limits and questions (nothing is saved).
document.querySelectorAll('[data-autosubmit]').forEach((input) => {
  input.addEventListener('change', () => {
    const form = input.form;
    const choose = document.createElement('input');
    choose.type = 'hidden'; choose.name = 'action'; choose.value = 'choose';
    form.appendChild(choose);
    form.submit();
  });
});

// Move focus to the error summary so screen-reader users hear it straight away.
document.getElementById('form-errors')?.focus();

// A plan drafted on the home page pre-fills a brand-new application (this tab only).
const appForm = document.getElementById('application-form');
if (appForm && appForm.dataset.prefill === '1') {
  try {
    const plan = JSON.parse(sessionStorage.getItem('lmdPlan') || 'null');
    if (plan && typeof plan.amount === 'number') {
      if (appForm.finance_type.querySelector(`option[value="${CSS.escape(String(plan.type))}"]`)) appForm.finance_type.value = plan.type;
      appForm.amount.value = plan.amount;
      appForm.term_months.value = plan.term;
    }
  } catch (e) { /* storage blocked */ }
}

// Help assistant: AI answer from published FAQs, or FAQ matches as a fallback.
const ask = document.querySelector('form[data-assistant]');
if (ask) {
  const out = document.querySelector('[data-answer]');
  const say = (nodes) => { out.replaceChildren(...nodes); };
  const para = (text, cls) => { const p = document.createElement('p'); p.textContent = text; if (cls) p.className = cls; return p; };
  ask.addEventListener('submit', async (e) => {
    e.preventDefault();
    const button = ask.querySelector('button');
    const token = document.querySelector('input[name="csrf_token"]')?.value || '';
    button.disabled = true;
    say([para('Looking that up…', 'muted')]);
    try {
      const res = await fetch('/account/help/ask', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ question: ask.question.value }),
      });
      const body = await res.json();
      if (!res.ok || !body.ok) throw new Error(body.error || 'Something went wrong.');
      if (body.source === 'ai') {
        say([para('AI-generated answer, based on our FAQs. It may be wrong; message the team about your application.', 'ai-label'), para(body.answer)]);
      } else if (body.matches.length) {
        say([para('These FAQs may help:', 'muted'), ...body.matches.map((m) => {
          const d = document.createElement('details'); d.className = 'faq'; d.open = true;
          const s = document.createElement('summary'); s.textContent = m.q;
          d.append(s, para(m.a)); return d;
        })]);
      } else {
        say([para("We couldn't find an answer. Please message the team or use the contact details on this page.")]);
      }
    } catch (err) {
      say([para(err.message, 'field-error')]);
    } finally {
      button.disabled = false;
    }
  });
}
