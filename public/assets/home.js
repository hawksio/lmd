// Home page: mount the scroll engine, then the two bespoke pieces.
const sc = window.ScrollCraft ? ScrollCraft.mount(document.body) : null;

// Example monthly repayment: standard amortisation, shown ONLY when staff have published a
// compliance-approved representative APR in /admin/content (the build writes it to data-example-apr).
// With no approved rate the page shows no repayment figures at all.
const liveForm = document.getElementById('live-plan');
const EXAMPLE_APR = liveForm ? parseFloat(liveForm.dataset.exampleApr) / 100 : NaN;
function monthly(amount, months) {
  if (!(EXAMPLE_APR > 0)) return null;
  const r = EXAMPLE_APR / 12;
  return (amount * r) / (1 - Math.pow(1 + r, -months));
}
const gbp = (n) => '£' + Math.round(n).toLocaleString('en-GB');

// Signature move, part 1: the plan writes itself out. Each row owns a threshold on the
// act's progress; its value fades in and its gold rule draws across as --r goes 0 to 1.
const planAct = document.querySelector('[data-plan-act]');
if (planAct) {
  const rows = [...planAct.querySelectorAll('[data-plan-at]')].map((el) => ({ el, at: parseFloat(el.dataset.planAt), r: -1 }));
  const act = sc && sc.acts.find((a) => a.el === planAct);
  const draw = () => {
    const p = act ? act.p : 1;
    for (const row of rows) {
      const r = Math.min(Math.max((p - row.at) / 0.07, 0), 1);
      if (Math.abs(r - row.r) > 0.002) { row.el.style.setProperty('--r', r.toFixed(3)); row.r = r; }
    }
  };
  let queued = false;
  addEventListener('scroll', () => {
    if (!queued) { queued = true; requestAnimationFrame(() => { draw(); queued = false; }); }
  }, { passive: true });
  addEventListener('resize', draw);
  draw();
}

// Signature move, part 2: the same plan, handed over. Live arithmetic, and the plan
// travels to the enquiry form (sessionStorage only; nothing leaves the browser until they send).
const form = document.getElementById('live-plan');
if (form) {
  const amount = form.querySelector('#lp-amount');
  const term = form.querySelector('#lp-term');
  const type = form.querySelector('#lp-type');
  const out = {
    amount: form.querySelector('#lp-amount-out'),
    term: form.querySelector('#lp-term-out'),
    monthly: form.querySelector('#lp-monthly'),
  };
  const update = () => {
    const a = +amount.value, n = +term.value;
    out.amount.value = gbp(a);
    out.term.value = n + ' months';
    if (out.monthly && monthly(a, n) !== null) out.monthly.value = gbp(monthly(a, n));
    amount.setAttribute('aria-valuetext', gbp(a));
    term.setAttribute('aria-valuetext', n + ' months');
  };
  form.addEventListener('input', update);
  const keep = () => {
    const plan = {
      type: type.value,
      typeLabel: type.options[type.selectedIndex].text,
      amount: +amount.value,
      term: +term.value,
      monthly: monthly(+amount.value, +term.value) === null ? null : Math.round(monthly(+amount.value, +term.value)),
      apr: EXAMPLE_APR > 0 ? EXAMPLE_APR * 100 : null,
    };
    try { sessionStorage.setItem('lmdPlan', JSON.stringify(plan)); } catch (err) { /* storage blocked: links still work */ }
  };
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    keep();
    location.href = 'contact.html#enquiry';
  });
  // "Apply online" carries the same plan into the application form (pre-fills amount, term, type).
  form.querySelector('[data-apply]')?.addEventListener('click', keep);
  update();
}
