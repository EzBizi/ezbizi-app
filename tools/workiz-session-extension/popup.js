// Runs only when you press Send: reads the three Workiz session cookies and
// posts them to the one-time link EzBizi gave you. Nothing runs in the background.
const NAMES = ['sendajob_sessid', 'sendajob_user', 'sajFranchise'];
const out = document.getElementById('out');

document.getElementById('send').addEventListener('click', async () => {
  const link = document.getElementById('link').value.trim();
  if (!link.startsWith('https://app.ezbizi.com/api/v1/social/workiz-web/handoff/')) {
    out.textContent = 'That is not an EzBizi Workiz link. Copy it again from Integrations.';
    return;
  }
  const cookies = {};
  for (const name of NAMES) {
    const c = await chrome.cookies.get({ url: 'https://app.workiz.com/', name });
    if (c) cookies[name] = c.value;
  }
  if (!cookies.sendajob_sessid) {
    out.textContent = 'You are not signed in to Workiz in this browser. Sign in first.';
    return;
  }
  out.textContent = 'Sending…';
  try {
    const r = await fetch(link, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ cookies }) });
    const body = await r.json().catch(() => ({}));
    out.textContent = r.ok ? 'Sent. EzBizi can read your Workiz reports now.' : (body.message || 'EzBizi refused it (' + r.status + '). Copy a new link and try again.');
  } catch (e) {
    out.textContent = 'Could not reach EzBizi. Check your connection and try again.';
  }
});
