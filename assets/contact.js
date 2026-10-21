// Contact page: pre-select the topic from Finance links, and carry over a plan drafted on the home page
// (sessionStorage, this browser tab only; nothing is sent until the visitor submits the form).
const t = new URLSearchParams(location.search).get('topic');
const topic = document.getElementById('topic');
if (t && topic && topic.querySelector(`option[value="${CSS.escape(t)}"]`)) topic.value = t;

let plan = null;
try { plan = JSON.parse(sessionStorage.getItem('lmdPlan')); } catch (e) { /* storage blocked */ }
if (plan && typeof plan.amount === 'number' && typeof plan.term === 'number') {
  const money = (n) => '£' + Math.round(n).toLocaleString('en-GB');
  if (topic && topic.querySelector(`option[value="${CSS.escape(String(plan.type))}"]`)) topic.value = plan.type;
  let summary = `${plan.typeLabel || 'Finance'}: ${money(plan.amount)} over ${plan.term} months`;
  if (typeof plan.monthly === 'number' && typeof plan.apr === 'number') {
    summary += ` (example monthly ${money(plan.monthly)} at a representative ${plan.apr}% APR)`;
  }
  const msg = document.getElementById('message');
  if (msg && !msg.value) msg.value = 'My draft plan: ' + summary + '.\n\n';
  const note = document.querySelector('[data-plan-note]');
  if (note) {
    note.textContent = 'We have added your draft plan to the message below. Change anything you like.';
    note.hidden = false;
  }
}
