/* ============================================================
   Contact — inquiry form with validation
   ============================================================ */

function Contact() {
  const [form, setForm] = useState({ name: '', email: '', company: '', type: '', message: '' });
  const [touched, setTouched] = useState({});
  const [submitted, setSubmitted] = useState(false);
  const [sent, setSent] = useState(false);

  const types = ['Custom CMS', 'SaaS product', 'API / integration', 'Website', 'Not sure yet'];

  const errors = {};
  if (!form.name.trim()) errors.name = 'Required';
  if (!form.email.trim()) errors.email = 'Required';
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) errors.email = 'Enter a valid email';
  if (!form.message.trim()) errors.message = 'Tell me a little about it';

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const blur = (k) => () => setTouched((t) => ({ ...t, [k]: true }));
  const showErr = (k) => (touched[k] || submitted) && errors[k];

  const submit = (e) => {
    e.preventDefault();
    setSubmitted(true);
    if (Object.keys(errors).length === 0) {
      setSent(true);
    }
  };

  const fieldStyle = (k) => ({
    width: '100%', background: 'var(--c-surface)', color: 'var(--c-ink)',
    border: '1px solid ' + (showErr(k) ? 'var(--c-accent)' : 'var(--c-line-2)'),
    padding: '13px 14px', fontSize: 15, fontFamily: 'var(--font-body)',
    outline: 'none', transition: 'border-color .2s ease',
  });

  const Label = ({ id, children }) => (
    <label htmlFor={id} className="tlabel" style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 9 }}>
      <span>{children}</span>
      {showErr(id) ? <span style={{ color: 'var(--c-accent)' }}>{errors[id]}</span> : null}
    </label>
  );

  return (
    <section id="contact" className="section" style={{ paddingBottom: 110 }}>
      <div className="shell">
        <SectionHeader no="§ 04" title="Contact" meta="START A PROJECT" />
        <div style={{ display: 'grid', gridTemplateColumns: '0.85fr 1.15fr', gap: 64 }} className="contact-grid">
          {/* left intro */}
          <div className="reveal">
            <h2 style={{
              fontFamily: 'var(--font-display)', fontWeight: 600,
              fontSize: 'clamp(28px, 3vw, 44px)', lineHeight: 1.05, letterSpacing: '-0.02em', margin: '0 0 22px',
            }}>
              Have an operation<br />that needs a system?
            </h2>
            <p style={{ color: 'var(--c-mute)', fontSize: 16, lineHeight: 1.65, maxWidth: 380, margin: '0 0 36px' }}>
              Tell me what you're running and where it's getting stuck. I'll come
              back with how I'd approach it — usually within a couple of business days.
            </p>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              {[['EMAIL', 'hello@kyleferguson.ca'], ['RESPONSE', 'Within 1–2 business days'], ['ENGAGEMENT', 'Project · retainer · advisory']].map(([k, v]) => (
                <div key={k} style={{ display: 'flex', gap: 16, alignItems: 'baseline', borderBottom: '1px solid var(--c-line)', paddingBottom: 14 }}>
                  <span className="tlabel" style={{ width: 96, flexShrink: 0, color: 'var(--c-faint)' }}>{k}</span>
                  <span style={{ fontSize: 14, color: 'var(--c-ink)' }}>{v}</span>
                </div>
              ))}
            </div>
          </div>

          {/* right form */}
          <div className="reveal" style={{ position: 'relative', border: '1px solid var(--c-line-2)', background: 'var(--c-bg)', padding: 34 }}>
            <CornerTicks />
            {sent ? (
              <div style={{ minHeight: 380, display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'flex-start' }}>
                <span className="btn__dot" style={{ width: 10, height: 10, boxShadow: '0 0 0 6px var(--c-accent-soft)' }} />
                <h3 style={{ fontFamily: 'var(--font-display)', fontSize: 28, fontWeight: 600, margin: '22px 0 12px' }}>Message received.</h3>
                <p style={{ color: 'var(--c-mute)', fontSize: 15, maxWidth: 360, margin: 0 }}>
                  Thanks, {form.name.split(' ')[0] || 'there'}. I've logged your inquiry and will
                  be in touch at <strong style={{ color: 'var(--c-ink)' }}>{form.email}</strong> shortly.
                </p>
                <p className="tlabel" style={{ marginTop: 28, color: 'var(--c-faint)' }}>
                  TICKET #KF-{Math.floor(1000 + Math.random() * 9000)} · LOGGED
                </p>
              </div>
            ) : (
              <form onSubmit={submit} noValidate>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 18, marginBottom: 18 }}>
                  <div>
                    <Label id="name">Name</Label>
                    <input id="name" style={fieldStyle('name')} value={form.name} onChange={set('name')} onBlur={blur('name')} placeholder="Your name" />
                  </div>
                  <div>
                    <Label id="email">Email</Label>
                    <input id="email" type="email" style={fieldStyle('email')} value={form.email} onChange={set('email')} onBlur={blur('email')} placeholder="you@company.com" />
                  </div>
                </div>
                <div style={{ marginBottom: 18 }}>
                  <Label id="company">Company / Organization <span style={{ color: 'var(--c-faint)' }}>(optional)</span></Label>
                  <input id="company" style={fieldStyle('company')} value={form.company} onChange={set('company')} placeholder="What you run" />
                </div>
                <div style={{ marginBottom: 18 }}>
                  <span className="tlabel" style={{ display: 'block', marginBottom: 11 }}>Project type</span>
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                    {types.map((t) => (
                      <button type="button" key={t} onClick={() => setForm((f) => ({ ...f, type: t }))}
                        style={{
                          fontFamily: 'var(--font-mono)', fontSize: 11, letterSpacing: '0.06em',
                          padding: '8px 13px', cursor: 'pointer',
                          background: form.type === t ? 'var(--c-accent)' : 'transparent',
                          color: form.type === t ? '#fff' : 'var(--c-ink-2)',
                          border: '1px solid ' + (form.type === t ? 'var(--c-accent)' : 'var(--c-line-2)'),
                          transition: 'all .15s ease',
                        }}>{t}</button>
                    ))}
                  </div>
                </div>
                <div style={{ marginBottom: 24 }}>
                  <Label id="message">Project details</Label>
                  <textarea id="message" rows={4} style={{ ...fieldStyle('message'), resize: 'vertical', lineHeight: 1.55 }}
                    value={form.message} onChange={set('message')} onBlur={blur('message')}
                    placeholder="What are you running, and where is it getting stuck?" />
                </div>
                <button type="submit" className="btn btn--solid" style={{ width: '100%', justifyContent: 'center', padding: '16px' }}>
                  Send inquiry <span className="arrow">→</span>
                </button>
              </form>
            )}
          </div>
        </div>
      </div>
    </section>
  );
}

Object.assign(window, { Contact });
