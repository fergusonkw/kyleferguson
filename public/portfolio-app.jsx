/* ============================================================
   App — production assembly (no tweaks panel)
   Defaults: Swiss type pairing, Index work layout, brick accent.
   ============================================================ */

function App() {
  useEffect(() => {
    const r = document.documentElement.style;
    r.setProperty('--font-display', "'Archivo', system-ui, sans-serif");
    r.setProperty('--font-body',    "'Archivo', system-ui, sans-serif");
    r.setProperty('--font-mono',    "'Space Mono', monospace");
    r.setProperty('--c-accent', '#c0392b');
    r.setProperty('--grid-show', '0');
  }, []);

  useReveal();

  return (
    <React.Fragment>
      <div className="blueprint-overlay" />
      <Nav />
      <Hero />
      <Work layout="index" />
      <Services />
      <Stack />
      <Contact />
      <Footer />
    </React.Fragment>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);
