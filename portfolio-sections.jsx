/* ============================================================
   Sections: Hero, Services, Tech/Capabilities, Contact
   ============================================================ */

/* ---------- HERO ---------- */
function Hero() {
  const go = (id) => (e) => {
    e.preventDefault();
    const el = document.getElementById(id);
    if (el) window.scrollTo({ top: el.offsetTop - 8, behavior: 'smooth' });
  };
  return (
    <header id="top" style={{ position: 'relative', paddingTop: 132, paddingBottom: 88 }}>
      <div className="shell">
        {/* eyebrow status line */}
        <div className="reveal" style={{ display: 'flex', alignItems: 'center', gap: 14, marginBottom: 40 }}>
          <span className="btn__dot" style={{ boxShadow: '0 0 0 4px var(--c-accent-soft)' }} />
          <span className="tlabel">Independent Software Developer</span>
          <span className="section__rule" style={{ maxWidth: 64 }} />
          <span className="tlabel" style={{ color: 'var(--c-faint)' }}>Available · 2026</span>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0,1fr) 300px', gap: 56, alignItems: 'end' }} className="hero-grid">
          {/* headline */}
          <div>
            <h1 className="reveal" style={{
              fontFamily: 'var(--font-display)', fontWeight: 600,
              fontSize: 'clamp(40px, 6.4vw, 96px)', lineHeight: 0.98,
              letterSpacing: '-0.02em', margin: 0, textWrap: 'balance',
            }}>
              Custom software<br />
              for operations<br />
              <span style={{ color: 'var(--c-mute)' }}>on the&nbsp;</span>
              <span style={{
                position: 'relative', whiteSpace: 'nowrap',
                textDecoration: 'underline', textDecorationColor: 'var(--c-accent)',
                textDecorationThickness: '3px', textUnderlineOffset: '0.08em',
              }}>
                ground
              </span>.
            </h1>
            <p className="reveal" style={{
              maxWidth: 560, marginTop: 32, marginBottom: 40,
              fontSize: 'clamp(16px, 1.3vw, 19px)', color: 'var(--c-ink-2)', lineHeight: 1.6,
            }}>
              I'm <strong style={{ color: 'var(--c-ink)', fontWeight: 600 }}>Kyle Ferguson</strong> — I design and build
              custom CMS platforms, SaaS products, and the web systems that keep
              field businesses, events, and organizations running.
            </p>
            <div className="reveal" style={{ display: 'flex', gap: 14, flexWrap: 'wrap' }}>
              <a href="#work" onClick={go('work')} className="btn btn--solid">
                View selected work <span className="arrow">→</span>
              </a>
              <a href="#contact" onClick={go('contact')} className="btn">
                <span className="btn__dot" /> Start a project
              </a>
            </div>
          </div>

          {/* engineering title-block */}
          <div className="reveal" style={{ position: 'relative' }}>
            <TitleBlock />
          </div>
        </div>
      </div>
    </header>
  );
}

/* blueprint title-block (drawing spec card) */
function TitleBlock() {
  const rows = [
    ['ROLE', 'Full-stack developer'],
    ['FOCUS', 'CMS · SaaS · APIs'],
    ['EXP', '15+ years shipping'],
    ['BASED', 'PEI, Canada'],
    ['STATUS', 'Booking 2026'],
  ];
  return (
    <div style={{ position: 'relative', border: '1px solid var(--c-line-2)', background: 'var(--c-surface)' }}>
      <CornerTicks />
      <div style={{ padding: '14px 16px', borderBottom: '1px solid var(--c-line)', display: 'flex', justifyContent: 'space-between' }}>
        <span className="tlabel tlabel--accent">SPEC</span>
        <span className="tlabel" style={{ color: 'var(--c-faint)' }}>REV 02</span>
      </div>
      {rows.map(([k, v], i) => (
        <div key={k} style={{
          display: 'flex', justifyContent: 'space-between', alignItems: 'center',
          padding: '13px 16px',
          borderBottom: i < rows.length - 1 ? '1px solid var(--c-line)' : 'none',
        }}>
          <span className="tlabel" style={{ color: 'var(--c-faint)' }}>{k}</span>
          <span style={{ fontSize: 13, color: 'var(--c-ink)', fontWeight: 500 }}>{v}</span>
        </div>
      ))}
    </div>
  );
}

/* ---------- SERVICES ---------- */
function Services() {
  const items = [
    ['01', 'Custom CMS Platforms', 'Bespoke content & operations management built around how a business actually works — scheduling, dispatch, billing, records — not a generic template bent to fit.'],
    ['02', 'SaaS Product Engineering', 'End-to-end products for organizations and events: multi-tenant architecture, dashboards, user roles, and the workflows that make them usable day one.'],
    ['03', 'APIs & Integrations', 'Documented, versioned APIs that power web and native clients alike — the connective tissue between your systems, data, and partners.'],
    ['04', 'Web & Marketing Sites', 'Fast, durable public-facing sites — from static brochure builds to data-driven pages that consume your live platform.'],
  ];
  return (
    <section id="services" className="section">
      <span className="tick" style={{ top: -5, left: -5 }} />
      <div className="shell">
        <SectionHeader no="§ 02" title="Services" meta="WHAT I BUILD" />
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: '1px', background: 'var(--c-line)', border: '1px solid var(--c-line)' }} className="svc-grid">
          {items.map(([no, title, body]) => (
            <div key={no} className="svc-cell reveal" style={{ background: 'var(--c-bg)', padding: '32px 30px' }}>
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 12, marginBottom: 18 }}>
                <span className="tlabel tlabel--accent">{no}</span>
                <h3 style={{ fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 22, margin: 0, letterSpacing: '-0.01em' }}>{title}</h3>
              </div>
              <p style={{ color: 'var(--c-mute)', fontSize: 15, lineHeight: 1.62, margin: 0 }}>{body}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ---------- TECH / CAPABILITIES ---------- */
function Stack() {
  const groups = [
    ['Backend', ['Node.js', 'PHP / Laravel', 'MySQL', 'REST APIs', 'Auth & RBAC']],
    ['Frontend', ['JavaScript', 'React', 'TypeScript', 'HTML / CSS', 'Design systems']],
    ['Mobile', ['Native iOS', 'Native Android', 'Offline-first', 'Push & sync']],
    ['Infrastructure', ['Linux / VPS', 'CI / CD', 'Backups', 'Monitoring', 'CDN']],
  ];
  return (
    <section id="stack" className="section">
      <div className="shell">
        <SectionHeader no="§ 03" title="Capabilities" meta="THE TOOLKIT" />
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 'var(--col-gap)' }} className="stack-grid">
          {groups.map(([title, tags]) => (
            <div key={title} className="reveal">
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 18, paddingBottom: 12, borderBottom: '1px solid var(--c-line)' }}>
                <span style={{ width: 5, height: 5, background: 'var(--c-accent)' }} />
                <span className="tlabel" style={{ color: 'var(--c-ink-2)' }}>{title}</span>
              </div>
              <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 10 }}>
                {tags.map((t) => (
                  <li key={t} style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 14, color: 'var(--c-ink-2)' }}>
                    <span style={{ fontFamily: 'var(--font-mono)', fontSize: 10, color: 'var(--c-faint)' }}>+</span>
                    {t}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
        <p className="reveal" style={{ marginTop: 40, fontSize: 13, color: 'var(--c-faint)', fontFamily: 'var(--font-mono)', letterSpacing: '0.04em' }}>
          // Tooling adapts to the job — these are the defaults, not the limits.
        </p>
      </div>
    </section>
  );
}

Object.assign(window, { Hero, Services, Stack });
