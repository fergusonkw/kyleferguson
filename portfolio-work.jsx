/* ============================================================
   Selected Work — 3 layout variants (index / cards / stacked)
   ============================================================ */

const PROJECTS = [
  {
    no: '01', name: 'Tracker Pull', type: 'SaaS + Native Apps', year: '2024',
    client: 'Tractor-pull events & organizations', role: 'Architecture · Full-stack · Mobile',
    summary: 'A multi-tenant SaaS for managing tractor-pull events and organizations — classes, entries, live scoring, and standings — with native iOS & Android companion apps and a documented public API.',
    stack: ['Laravel', 'iOS', 'Android', 'REST API', 'React', 'MySQL'],
    scope: ['SaaS', 'Native apps', 'Public API'],
  },
  {
    no: '02', name: 'Crapaud Exhibition', type: 'Marketing Site', year: '2022',
    client: 'Regional exhibition & fair', role: 'Design + Build',
    summary: 'A fast, durable static marketing site for a regional exhibition — schedule, attractions, and visitor information — built to absorb seasonal traffic spikes without breaking a sweat.',
    stack: ['Static', 'HTML/CSS', 'JS'],
    scope: ['Static', 'Marketing'],
  },
  {
    no: '03', name: 'A & M Snow', type: 'Operations CMS', year: '2023',
    client: 'Regional snow-removal company', role: 'Design + Full-stack',
    summary: 'A custom CMS that runs a snow-removal business end to end — customer accounts, site routes, dispatch scheduling, service logging, and seasonal billing in one operational hub.',
    stack: ['Laravel', 'MySQL', 'Android', 'REST API'],
    scope: ['Dispatch', 'Routing', 'Billing'],
  },
  {
    no: '04', name: 'PEI Truck & Tractor Pulls', type: 'Data-driven Page', year: '2024',
    client: 'Local tractor-pull event', role: 'Frontend',
    summary: 'A public results page for a local tractor pull that consumes the Tracker Pull API — live standings and class results, updating in step with the event as it runs.',
    stack: ['Laravel', 'API client', 'JS'],
    scope: ['API consumer', 'Live data'],
  },
];

/* striped media placeholder with monospace explainer */
function Media({ label, ratio = '16 / 10' }) {
  return (
    <div style={{
      position: 'relative', aspectRatio: ratio, width: '100%',
      background:
        'repeating-linear-gradient(135deg, var(--c-surface) 0, var(--c-surface) 9px, var(--c-surface-2) 9px, var(--c-surface-2) 18px)',
      border: '1px solid var(--c-line)',
      display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden',
    }}>
      <span className="tlabel" style={{
        background: 'var(--c-bg)', padding: '5px 11px', border: '1px solid var(--c-line-2)',
        color: 'var(--c-mute)', letterSpacing: '0.12em',
      }}>{label}</span>
    </div>
  );
}

function StackTags({ items, accentFirst }) {
  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
      {items.map((s, i) => (
        <span key={s} className="tlabel" style={{
          fontSize: 10, padding: '4px 9px', border: '1px solid var(--c-line-2)',
          color: accentFirst && i === 0 ? 'var(--c-accent)' : 'var(--c-mute)',
          letterSpacing: '0.1em',
        }}>{s}</span>
      ))}
    </div>
  );
}

/* ---------- LAYOUT A: INDEX (ledger rows, expand on click) ---------- */
function WorkIndex() {
  const [open, setOpen] = useState(0);
  return (
    <div style={{ borderTop: '1px solid var(--c-line-2)' }}>
      {PROJECTS.map((p, i) => {
        const isOpen = open === i;
        return (
          <div key={p.no} className="reveal" style={{ borderBottom: '1px solid var(--c-line)' }}>
            <button onClick={() => setOpen(isOpen ? -1 : i)} className="ledger-row" style={{
              width: '100%', background: 'transparent', border: 'none', cursor: 'pointer',
              display: 'grid', gridTemplateColumns: '64px minmax(0,1fr) 180px 120px 40px',
              alignItems: 'center', gap: 16, padding: '26px 8px', textAlign: 'left',
              color: 'inherit',
            }}>
              <span className="tlabel" style={{ color: isOpen ? 'var(--c-accent)' : 'var(--c-faint)' }}>{p.no}</span>
              <span style={{
                fontFamily: 'var(--font-display)', fontWeight: 600,
                fontSize: 'clamp(22px, 2.4vw, 32px)', letterSpacing: '-0.01em',
                color: isOpen ? 'var(--c-ink)' : 'var(--c-ink-2)', transition: 'color .2s',
              }}>{p.name}</span>
              <span className="tlabel ledger-hide" style={{ color: 'var(--c-mute)' }}>{p.type}</span>
              <span className="tlabel ledger-hide" style={{ color: 'var(--c-faint)' }}>{p.year}</span>
              <span className="arrow" style={{
                justifySelf: 'end', color: isOpen ? 'var(--c-accent)' : 'var(--c-mute)',
                transform: isOpen ? 'rotate(90deg)' : 'none', transition: 'transform .25s, color .2s', fontSize: 18,
              }}>→</span>
            </button>
            <div style={{
              display: 'grid', gridTemplateRows: isOpen ? '1fr' : '0fr',
              transition: 'grid-template-rows .35s cubic-bezier(.16,.84,.44,1)',
            }}>
              <div style={{ overflow: 'hidden' }}>
                <div style={{ display: 'grid', gridTemplateColumns: '1.2fr 1fr', gap: 40, padding: '4px 8px 36px' }} className="ledger-detail">
                  <div>
                    <p style={{ fontSize: 16, lineHeight: 1.6, color: 'var(--c-ink-2)', margin: '0 0 22px', maxWidth: 480 }}>{p.summary}</p>
                    <div style={{ display: 'flex', gap: 40, flexWrap: 'wrap', marginBottom: 22 }}>
                      <Meta k="Client" v={p.client} />
                      <Meta k="Role" v={p.role} />
                    </div>
                    <StackTags items={p.stack} accentFirst />
                  </div>
                  <Media label={'[ ' + p.name.toUpperCase() + ' — SCREEN ]'} ratio="16 / 10" />
                </div>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

function Meta({ k, v }) {
  return (
    <div>
      <div className="tlabel" style={{ color: 'var(--c-faint)', marginBottom: 6 }}>{k}</div>
      <div style={{ fontSize: 14, color: 'var(--c-ink)' }}>{v}</div>
    </div>
  );
}

/* ---------- LAYOUT B: CARDS (blueprint grid) ---------- */
function WorkCards() {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 'var(--col-gap)' }} className="cards-grid">
      {PROJECTS.map((p) => (
        <article key={p.no} className="reveal work-card" style={{
          position: 'relative', border: '1px solid var(--c-line-2)', background: 'var(--c-surface)',
          padding: 22, transition: 'border-color .25s ease, transform .25s ease',
        }}>
          <CornerTicks />
          <Media label={'[ ' + p.name.toUpperCase() + ' ]'} ratio="16 / 9" />
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginTop: 22, marginBottom: 4 }}>
            <span className="tlabel tlabel--accent">{p.no} / {p.type}</span>
            <span className="tlabel" style={{ color: 'var(--c-faint)' }}>{p.year}</span>
          </div>
          <h3 style={{ fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 26, margin: '0 0 14px', letterSpacing: '-0.01em' }}>{p.name}</h3>
          <p style={{ color: 'var(--c-mute)', fontSize: 14.5, lineHeight: 1.6, margin: '0 0 20px' }}>{p.summary}</p>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingTop: 18, borderTop: '1px solid var(--c-line)' }}>
            <StackTags items={p.stack} accentFirst />
            <span className="arrow" style={{ color: 'var(--c-mute)', fontSize: 18 }}>→</span>
          </div>
        </article>
      ))}
    </div>
  );
}

/* ---------- LAYOUT C: STACKED (large numbered entries) ---------- */
function WorkStacked() {
  return (
    <div style={{ display: 'flex', flexDirection: 'column' }}>
      {PROJECTS.map((p, i) => (
        <article key={p.no} className="reveal stacked-row" style={{
          display: 'grid', gridTemplateColumns: '0.95fr 1.05fr', gap: 56, alignItems: 'center',
          padding: '52px 0', borderTop: '1px solid var(--c-line)',
        }}>
          <div style={{ order: i % 2 === 0 ? 0 : 2 }}>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 16, marginBottom: 18 }}>
              <span style={{
                fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 'clamp(44px, 5vw, 76px)',
                lineHeight: 1, color: 'transparent', WebkitTextStroke: '1px var(--c-line-2)', letterSpacing: '-0.02em',
              }}>{p.no}</span>
              <span className="tlabel tlabel--accent">{p.type} · {p.year}</span>
            </div>
            <h3 style={{ fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 'clamp(30px, 3.4vw, 48px)', margin: '0 0 18px', letterSpacing: '-0.02em', lineHeight: 1 }}>{p.name}</h3>
            <p style={{ color: 'var(--c-mute)', fontSize: 16, lineHeight: 1.62, maxWidth: 460, margin: '0 0 24px' }}>{p.summary}</p>
            <div style={{ display: 'flex', gap: 36, flexWrap: 'wrap', marginBottom: 22 }}>
              <Meta k="Client" v={p.client} />
              <Meta k="Role" v={p.role} />
            </div>
            <StackTags items={p.stack} accentFirst />
          </div>
          <div style={{ order: 1, position: 'relative' }}>
            <Media label={'[ ' + p.name.toUpperCase() + ' — SCREEN ]'} ratio="4 / 3" />
          </div>
        </article>
      ))}
    </div>
  );
}

function Work({ layout }) {
  return (
    <section id="work" className="section">
      <span className="tick" style={{ top: -5, right: -5 }} />
      <div className="shell">
        <SectionHeader no="§ 01" title="Selected Work" meta={'04 PROJECTS / ' + layout.toUpperCase()} />
        {layout === 'index' && <WorkIndex />}
        {layout === 'cards' && <WorkCards />}
        {layout === 'stacked' && <WorkStacked />}
      </div>
    </section>
  );
}

Object.assign(window, { Work, PROJECTS });
