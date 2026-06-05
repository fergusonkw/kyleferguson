/* ============================================================
   Shared components: Nav, SectionHeader, Footer, hooks, ticks
   ============================================================ */
const { useState, useEffect, useRef, useCallback } = React;

/* reveal-on-scroll hook (minimal, crisp) — rect-based, IO-independent */
function useReveal() {
  useEffect(() => {
    let raf = 0;
    const check = () => {
      const vh = window.innerHeight || document.documentElement.clientHeight;
      document.querySelectorAll('.reveal:not(.is-in)').forEach((e) => {
        const r = e.getBoundingClientRect();
        if (r.top < vh * 0.92 && r.bottom > 0) e.classList.add('is-in');
      });
    };
    const onScroll = () => { cancelAnimationFrame(raf); raf = requestAnimationFrame(check); };
    check();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    const t1 = setTimeout(check, 120);
    const t2 = setTimeout(check, 450);
    // failsafe: if a throttled/hidden compositor freezes the opacity transition
    // mid-flight, force every reveal to its end-state so content is never invisible.
    const t3 = setTimeout(() => document.documentElement.classList.add('reveal-failsafe'), 1600);
    return () => {
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onScroll);
      clearTimeout(t1); clearTimeout(t2); clearTimeout(t3); cancelAnimationFrame(raf);
    };
  });
}

/* corner crosshair ticks for a framed block */
function CornerTicks() {
  return (
    <React.Fragment>
      <span className="tick" style={{ top: -4, left: -4 }} />
      <span className="tick" style={{ top: -4, right: -4 }} />
      <span className="tick" style={{ bottom: -4, left: -4 }} />
      <span className="tick" style={{ bottom: -4, right: -4 }} />
    </React.Fragment>
  );
}

function SectionHeader({ no, title, meta }) {
  return (
    <div className="section__index reveal">
      <span className="section__no">{no}</span>
      <span className="section__title">{title}</span>
      <span className="section__rule" />
      {meta ? <span className="section__meta">{meta}</span> : null}
    </div>
  );
}

/* ---- Top navigation ---- */
function Nav({ onNavigate }) {
  const [scrolled, setScrolled] = useState(false);
  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 24);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  const links = [
    ['01', 'Work', 'work'],
    ['02', 'Services', 'services'],
    ['03', 'Stack', 'stack'],
    ['04', 'Contact', 'contact'],
  ];

  const go = (id) => (e) => {
    e.preventDefault();
    const el = document.getElementById(id);
    if (el) window.scrollTo({ top: el.offsetTop - 8, behavior: 'smooth' });
  };

  return (
    <nav style={{
      position: 'fixed', top: 0, left: 0, right: 0, zIndex: 50,
      borderBottom: '1px solid ' + (scrolled ? 'var(--c-line)' : 'transparent'),
      background: scrolled ? 'color-mix(in oklch, var(--c-bg) 82%, transparent)' : 'transparent',
      backdropFilter: scrolled ? 'blur(12px)' : 'none',
      transition: 'background .3s ease, border-color .3s ease',
    }}>
      <div className="shell" style={{
        display: 'flex', alignItems: 'center', justifyContent: 'space-between',
        height: 64,
      }}>
        <a href="#top" onClick={go('top')} style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <Monogram />
          <span style={{
            fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 15,
            letterSpacing: '0.04em',
          }}>Kyle Ferguson</span>
        </a>
        <div style={{ display: 'flex', alignItems: 'center', gap: 4 }} className="nav-links">
          {links.map(([n, label, id]) => (
            <a key={id} href={'#' + id} onClick={go(id)} className="nav-link">
              <span style={{ color: 'var(--c-faint)', fontFamily: 'var(--font-mono)', fontSize: 10, marginRight: 6 }}>{n}</span>
              {label}
            </a>
          ))}
          <a href="#contact" onClick={go('contact')} className="btn" style={{ marginLeft: 16, padding: '10px 18px' }}>
            <span className="btn__dot" />
            Start a project
          </a>
        </div>
      </div>
    </nav>
  );
}

/* small precise monogram — square with KF notch (no illustration) */
function Monogram() {
  return (
    <span style={{
      position: 'relative', width: 30, height: 30,
      border: '1px solid var(--c-line-2)', display: 'inline-flex',
      alignItems: 'center', justifyContent: 'center', flexShrink: 0,
    }}>
      <span style={{
        position: 'absolute', top: -1, left: -1, width: 6, height: 6,
        borderTop: '1px solid var(--c-accent)', borderLeft: '1px solid var(--c-accent)',
      }} />
      <span style={{
        fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 12,
        letterSpacing: '-0.02em', color: 'var(--c-ink)',
      }}>KF</span>
    </span>
  );
}

/* ---- Footer ---- */
function Footer() {
  const year = 2026;
  return (
    <footer style={{ borderTop: '1px solid var(--c-line)', background: 'var(--c-void)' }}>
      <div className="shell" style={{ padding: '56px 40px 40px' }}>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 40, justifyContent: 'space-between', alignItems: 'flex-end' }}>
          <div style={{ maxWidth: 420 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
              <Monogram />
              <span style={{ fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 16 }}>Kyle Ferguson</span>
            </div>
            <p style={{ color: 'var(--c-mute)', fontSize: 14, margin: 0, lineHeight: 1.6 }}>
              Custom CMS platforms, SaaS products, and web systems
              that run real-world operations.
            </p>
          </div>
          <div>
            <div className="tlabel" style={{ marginBottom: 12 }}>Direct</div>
            <a href="mailto:hello@kyleferguson.ca" className="footer-link">hello@kyleferguson.ca</a>
            <a href="#contact" className="footer-link">Start a project →</a>
          </div>
        </div>
        <div style={{
          marginTop: 48, paddingTop: 20, borderTop: '1px solid var(--c-line)',
          display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12,
        }}>
          <span className="tlabel" style={{ color: 'var(--c-faint)' }}>© {year} Kyle Ferguson — All systems operational</span>
          <span className="tlabel" style={{ color: 'var(--c-faint)' }}>Lat 46.2 / Lon −63.4 · PEI, CA</span>
        </div>
      </div>
    </footer>
  );
}

Object.assign(window, { useReveal, CornerTicks, SectionHeader, Nav, Monogram, Footer });
