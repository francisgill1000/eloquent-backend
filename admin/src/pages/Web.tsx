import { useEffect, useRef } from 'react';

/* Landing page for admin.eloquentservice.com/web.
   Ported verbatim from the standalone marketing HTML reference. The reference
   relies on GLOBAL css (:root, body, *, h1, section, a, :focus-visible), so we
   inject it into <head> only while this route is mounted and strip it on
   unmount — otherwise it would restyle the whole admin SPA. We also hide the
   admin's ambient background layers (particle canvas + grid/glow) so the
   landing's own background is the only one showing. */

const LP_CSS = `
:root{
  --bg-0:#05070a; --bg-1:#0a0e0c; --bg-2:#0f1411;
  --surface-1:#131816; --surface-2:#1a201d; --surface-3:#232b27;
  --mint-300:#00d4aa; --mint-400:#00e6b8; --mint-500:#00ffcc; --mint-600:#00b894;
  --mint-glow:rgba(0,255,204,0.22); --mint-soft:rgba(0,255,204,0.08);
  --text-1:#f3f5f4; --text-2:#c5cbc8; --text-3:#8a938f;
  --border-1:rgba(255,255,255,0.06); --border-2:rgba(255,255,255,0.09); --border-mint:rgba(0,255,204,0.24);
  --warn:#f4b860; --danger:#f87171; --info:#60a5fa;
  --r-sm:8px; --r-md:12px; --r-lg:16px; --r-xl:22px; --r-pill:999px;
  --shadow-1:0 1px 0 rgba(255,255,255,0.03) inset, 0 1px 2px rgba(0,0,0,0.4);
  --shadow-glow:0 0 0 1px rgba(0,255,204,0.35), 0 12px 40px -10px rgba(0,255,204,0.35);
  --maxw:1120px;
}
/* Keep the admin app's ambient background (ParticleField constellation +
   tokens.css glow/grid) — the landing sits on the same base as the post-login
   app, so we DON'T hide .fx-canvas or body::before/after here. */
.lp-scope *{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{
  font-family:'Geist',ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;
  -webkit-font-smoothing:antialiased;
  color:var(--text-1);
  background:var(--bg-1);
  line-height:1.5;
  overflow-x:hidden;
}
.lp-scope a{color:inherit;text-decoration:none}
.lp-scope .wrap{max-width:var(--maxw);margin:0 auto;padding:0 24px}
.lp-scope .mono{font-family:'Geist Mono',ui-monospace,monospace}

.lp-scope .btn{
  display:inline-flex;align-items:center;gap:8px;
  font-weight:600;font-size:15px;line-height:1;
  padding:14px 22px;border-radius:var(--r-pill);
  border:1px solid transparent;cursor:pointer;
  transition:transform .15s ease, box-shadow .2s ease, background .2s ease, border-color .2s ease, color .2s ease;
  white-space:nowrap;
}
.lp-scope .btn-primary{background:var(--mint-500);color:#03130b;box-shadow:var(--shadow-1)}
.lp-scope .btn-primary:hover{box-shadow:var(--shadow-glow);transform:translateY(-1px)}
.lp-scope .btn-secondary{background:transparent;color:var(--text-1);border-color:var(--border-2)}
.lp-scope .btn-secondary:hover{color:var(--mint-500);border-color:var(--border-mint)}
.lp-scope .btn svg{width:17px;height:17px}
.lp-scope :focus-visible{outline:2px solid var(--mint-500);outline-offset:3px;border-radius:6px}

.lp-scope header.nav{position:sticky;top:0;z-index:50;background:transparent}
.lp-scope .nav-inner{display:flex;align-items:center;justify-content:space-between;height:66px}
.lp-scope .brand{display:flex;align-items:center;gap:10px;font-weight:600;letter-spacing:-0.01em}
.lp-scope .brand .dot{width:22px;height:22px;border-radius:7px;
  background:linear-gradient(135deg,var(--mint-400),var(--mint-600));
  box-shadow:0 0 18px var(--mint-glow);display:grid;place-items:center}
.lp-scope .brand .dot svg{width:13px;height:13px;color:#03130b}
.lp-scope .brand small{color:var(--text-3);font-weight:400;font-size:12px;margin-left:2px}
.lp-scope .nav-cta{display:flex;align-items:center;gap:14px}
.lp-scope .nav-login{font-size:14px;color:var(--text-2);font-weight:500}
.lp-scope .nav-login:hover{color:var(--mint-500)}
@media(max-width:560px){.lp-scope .nav-login{display:none}}

.lp-scope .hero{padding:72px 0 40px}
.lp-scope .hero-grid{display:grid;grid-template-columns:1.05fr 0.95fr;gap:48px;align-items:center}
.lp-scope .eyebrow{display:inline-flex;align-items:center;gap:8px;
  font-size:12.5px;font-weight:500;letter-spacing:0.02em;color:var(--mint-300);
  background:var(--mint-soft);border:1px solid var(--border-mint);
  padding:6px 13px;border-radius:var(--r-pill);margin-bottom:22px}
.lp-scope .eyebrow .ping{width:7px;height:7px;border-radius:50%;background:var(--mint-500);
  box-shadow:0 0 0 0 var(--mint-glow);animation:lpPing 2.4s infinite}
@keyframes lpPing{0%{box-shadow:0 0 0 0 rgba(0,255,204,0.4)}70%{box-shadow:0 0 0 8px rgba(0,255,204,0)}100%{box-shadow:0 0 0 0 rgba(0,255,204,0)}}
.lp-scope h1{font-size:clamp(34px,5.2vw,56px);line-height:1.04;font-weight:700;letter-spacing:-0.025em}
.lp-scope h1 .accent{background:linear-gradient(120deg,var(--mint-400),var(--mint-500));
  -webkit-background-clip:text;background-clip:text;color:transparent}
.lp-scope .hero p.sub{margin-top:20px;font-size:clamp(16px,2.2vw,18.5px);color:var(--text-2);max-width:33ch}
.lp-scope .hero-actions{margin-top:32px;display:flex;gap:12px;flex-wrap:wrap}
.lp-scope .hero-note{margin-top:16px;font-size:13px;color:var(--text-3);display:flex;align-items:center;gap:7px}
.lp-scope .hero-note svg{width:15px;height:15px;color:var(--mint-400)}

/* ---- Hero product slider (Business Lens + Business Hunt) ---- */
.lp-scope .hero-slider{position:relative;overflow:hidden}
.lp-scope .hero-track{display:flex;transition:transform .55s cubic-bezier(.4,0,.2,1);will-change:transform}
.lp-scope .hero-slide{flex:0 0 100%;min-width:100%}
.lp-scope .slider-nav{display:flex;align-items:center;justify-content:center;gap:16px;padding:4px 0 10px}
.lp-scope .slider-arrow{width:40px;height:40px;flex:none;border-radius:50%;display:grid;place-items:center;
  background:var(--surface-1);border:1px solid var(--border-2);color:var(--text-2);cursor:pointer;
  transition:color .2s ease,border-color .2s ease,transform .15s ease}
.lp-scope .slider-arrow:hover{color:var(--mint-500);border-color:var(--border-mint);transform:translateY(-1px)}
.lp-scope .slider-arrow svg{width:18px;height:18px}
.lp-scope .slider-dots{display:flex;align-items:center;gap:9px}
.lp-scope .slider-dots button{width:9px;height:9px;padding:0;border:none;border-radius:var(--r-pill);
  background:var(--border-2);cursor:pointer;transition:background .25s ease,width .25s ease}
.lp-scope .slider-dots button.active{background:var(--mint-500);width:26px}
@media(prefers-reduced-motion:reduce){.lp-scope .hero-track{transition:none}}

.lp-scope .demo{background:linear-gradient(180deg,var(--surface-2),var(--surface-1));
  border:1px solid var(--border-2);border-radius:var(--r-xl);
  box-shadow:0 30px 80px -30px rgba(0,0,0,0.8), var(--shadow-1);
  padding:20px;position:relative;overflow:hidden}
.lp-scope .demo::before{content:"";position:absolute;inset:0;
  background:radial-gradient(500px 200px at 80% 0%,rgba(0,255,204,0.10),transparent 60%);pointer-events:none}
.lp-scope .demo-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.lp-scope .demo-top .who{display:flex;align-items:center;gap:9px;font-size:13px;color:var(--text-2)}
.lp-scope .demo-top .who .av{width:26px;height:26px;border-radius:50%;
  background:linear-gradient(135deg,var(--mint-400),var(--mint-600));display:grid;place-items:center}
.lp-scope .demo-top .who .av svg{width:14px;height:14px;color:#03130b}
.lp-scope .badge{font-size:11px;font-weight:500;color:var(--mint-300);background:var(--mint-soft);
  border:1px solid var(--border-mint);padding:4px 10px;border-radius:var(--r-pill);
  display:inline-flex;align-items:center;gap:6px}
.lp-scope .badge .rec{width:6px;height:6px;border-radius:50%;background:var(--mint-500)}

.lp-scope .mic{display:flex;align-items:center;gap:12px;
  background:var(--bg-2);border:1px solid var(--border-1);border-radius:var(--r-md);padding:13px 15px}
.lp-scope .mic .icon{width:34px;height:34px;flex:none;border-radius:50%;
  background:var(--mint-soft);border:1px solid var(--border-mint);display:grid;place-items:center}
.lp-scope .mic .icon svg{width:16px;height:16px;color:var(--mint-400)}
.lp-scope .wave{display:flex;align-items:center;gap:3px;height:22px;flex:1}
.lp-scope .wave span{width:3px;border-radius:2px;background:var(--mint-500);opacity:.85;
  height:20%;animation:lpEq 1.1s ease-in-out infinite}
.lp-scope .wave span:nth-child(2n){animation-delay:.15s}
.lp-scope .wave span:nth-child(3n){animation-delay:.3s}
.lp-scope .wave span:nth-child(4n){animation-delay:.45s}
@keyframes lpEq{0%,100%{height:15%}50%{height:95%}}
.lp-scope .paused .wave span{animation-play-state:paused;height:18%}

.lp-scope .q{margin:15px 2px 4px;font-size:14px;color:var(--text-2)}
.lp-scope .q b{color:var(--text-1);font-weight:500}
.lp-scope .answer{margin-top:12px;background:var(--bg-2);border:1px solid var(--border-1);
  border-radius:var(--r-md);padding:16px;min-height:118px}
.lp-scope .answer .line{font-size:13.5px;color:var(--text-2);margin-bottom:9px;display:flex;gap:9px;opacity:0;
  transform:translateY(4px);transition:opacity .35s ease, transform .35s ease}
.lp-scope .answer .line.show{opacity:1;transform:none}
.lp-scope .answer .line .k{color:var(--text-3);min-width:112px}
.lp-scope .answer .line .v{color:var(--mint-300);font-family:'Geist Mono',monospace;font-weight:500}
.lp-scope .cursor{display:inline-block;width:8px;height:15px;background:var(--mint-500);
  margin-left:2px;vertical-align:-2px;animation:lpBlink 1s steps(2) infinite}
@keyframes lpBlink{50%{opacity:0}}

.lp-scope section{padding:64px 0}
.lp-scope .sec-head{max-width:640px;margin-bottom:44px}
.lp-scope .sec-eyebrow{font-family:'Geist Mono',monospace;font-size:12px;letter-spacing:0.08em;
  text-transform:uppercase;color:var(--mint-300);font-weight:500}
.lp-scope .sec-head h2{font-size:clamp(26px,3.6vw,38px);font-weight:600;letter-spacing:-0.02em;margin-top:12px}
.lp-scope .sec-head p{color:var(--text-2);margin-top:14px;font-size:16.5px}

.lp-scope .steps{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.lp-scope .step{background:var(--surface-1);border:1px solid var(--border-1);border-radius:var(--r-lg);
  padding:26px;position:relative;transition:border-color .25s ease, transform .25s ease}
.lp-scope .step:hover{border-color:var(--border-mint);transform:translateY(-3px)}
.lp-scope .step .no{font-family:'Geist Mono',monospace;font-size:13px;color:var(--mint-500);font-weight:600}
.lp-scope .step .ic{width:42px;height:42px;border-radius:var(--r-md);margin:16px 0 14px;
  background:var(--mint-soft);border:1px solid var(--border-mint);display:grid;place-items:center}
.lp-scope .step .ic svg{width:21px;height:21px;color:var(--mint-400)}
.lp-scope .step h3{font-size:18px;font-weight:600;letter-spacing:-0.01em}
.lp-scope .step p{color:var(--text-2);font-size:14.5px;margin-top:9px}
.lp-scope .step .rail{position:absolute;top:34px;right:-9px;width:18px;height:2px;background:var(--border-mint);z-index:2}
@media(max-width:820px){.lp-scope .step .rail{display:none}}

.lp-scope .props{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.lp-scope .prop{background:linear-gradient(180deg,var(--surface-1),var(--bg-2));
  border:1px solid var(--border-1);border-radius:var(--r-lg);padding:26px}
.lp-scope .prop .ic{width:44px;height:44px;border-radius:var(--r-md);display:grid;place-items:center;
  background:var(--mint-soft);border:1px solid var(--border-mint);margin-bottom:16px}
.lp-scope .prop .ic svg{width:22px;height:22px;color:var(--mint-400)}
.lp-scope .prop h3{font-size:18px;font-weight:600}
.lp-scope .prop p{color:var(--text-2);font-size:14.5px;margin-top:9px}
.lp-scope .prop .tag{margin-top:14px;font-family:'Geist Mono',monospace;font-size:11.5px;
  color:var(--mint-300);letter-spacing:0.03em}

.lp-scope .who-sec{background:linear-gradient(180deg,transparent,rgba(0,255,204,0.02))}
.lp-scope .chips{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px}
.lp-scope .chip{display:inline-flex;align-items:center;gap:8px;font-size:14px;color:var(--text-2);
  background:var(--surface-1);border:1px solid var(--border-2);padding:10px 16px;border-radius:var(--r-pill)}
.lp-scope .chip .d{width:6px;height:6px;border-radius:50%;background:var(--mint-500)}

.lp-scope .cta-band{background:
    radial-gradient(700px 300px at 50% 0%,rgba(0,255,204,0.14),transparent 60%),
    linear-gradient(180deg,var(--surface-1),var(--bg-2));
  border:1px solid var(--border-2);border-radius:var(--r-xl);
  padding:56px 32px;text-align:center;box-shadow:var(--shadow-1)}
.lp-scope .cta-band h2{font-size:clamp(26px,3.6vw,40px);font-weight:700;letter-spacing:-0.025em}
.lp-scope .cta-band p{color:var(--text-2);margin-top:14px;font-size:17px}
.lp-scope .cta-band .hero-actions{justify-content:center;margin-top:28px}

.lp-scope footer{border-top:1px solid var(--border-1);margin-top:24px;padding:40px 0}
.lp-scope .foot{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px}
.lp-scope .foot small{color:var(--text-3);font-size:13px}
.lp-scope .foot .links{display:flex;gap:20px;font-size:13.5px;color:var(--text-2)}
.lp-scope .foot .links a:hover{color:var(--mint-500)}

@media(max-width:820px){
  .lp-scope .hero-grid{grid-template-columns:1fr;gap:36px}
  .lp-scope .hero{padding:48px 0 20px}
  .lp-scope .hero p.sub{max-width:none}
  .lp-scope .steps,.lp-scope .props{grid-template-columns:1fr}
  .lp-scope section{padding:52px 0}
}
@media(prefers-reduced-motion:reduce){
  .lp-scope *{animation:none!important;transition:none!important}
  html{scroll-behavior:auto}
  .lp-scope .answer .line{opacity:1;transform:none}
}
`;

const LP_HTML = `
<header class="nav">
  <div class="wrap nav-inner">
    <div class="brand">
      <span class="dot" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg>
      </span>
      Eloquent <span style="font-weight:400;color:var(--text-2)">Business&nbsp;Lens</span>
    </div>
    <nav class="nav-cta">
      <a class="nav-login" href="https://admin.eloquentservice.com">Log in</a>
      <a class="btn btn-primary" href="https://admin.eloquentservice.com">Start free</a>
    </nav>
  </div>
</header>

<main>
  <div class="hero-slider">
   <div class="hero-track" id="lp-track">
    <div class="hero-slide">
  <section class="hero">
    <div class="wrap hero-grid">
      <div>
        <span class="eyebrow"><span class="ping"></span> AI booking Lens · Built in the UAE</span>
        <h1>Take bookings by QR.<br><span class="accent">Let AI handle them.</span><br>Manage your day by voice.</h1>
        <p class="sub">Your customers scan and book in seconds. AI does the scheduling. You run the whole thing — even your reports — just by asking out loud.</p>
        <div class="hero-actions">
          <a class="btn btn-primary" href="https://admin.eloquentservice.com">
            Start free
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
          </a>
          <a class="btn btn-secondary" href="https://wa.me/971557369629?text=Hi%2C%20I%27d%20like%20a%20quick%20demo%20of%20Eloquent%20Business%20Lens">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2Zm0 18.15c-1.52 0-3.01-.41-4.31-1.18l-.31-.18-3.2.84.85-3.12-.2-.32a8.2 8.2 0 0 1-1.26-4.36c0-4.54 3.7-8.24 8.24-8.24 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.41 5.83c0 4.54-3.69 8.24-8.23 8.24Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.12-.17.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.16-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.43-.14-.01-.31-.01-.48-.01-.17 0-.43.06-.66.31-.23.25-.87.85-.87 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.14-1.18-.06-.11-.22-.17-.47-.29Z"/></svg>
          </a>
        </div>
        <div class="hero-note">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>
          No app for your customers · First month free
        </div>
      </div>

      <div class="demo" id="lp-demo">
        <div class="demo-top">
          <span class="who">
            <span class="av"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg></span>
            Voice mode
          </span>
          <span class="badge"><span class="rec"></span> Listening</span>
        </div>

        <div class="mic" id="lp-mic">
          <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg></span>
          <div class="wave" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
          </div>
        </div>

        <p class="q">You ask: <b id="lp-qtext">"How did we do today?"</b></p>

        <div class="answer" id="lp-answer" aria-live="polite">
          <div class="line" data-i="0"><span class="k">Bookings today</span><span class="v">14 confirmed</span></div>
          <div class="line" data-i="1"><span class="k">Still open</span><span class="v">3 slots · 4–6 PM</span></div>
          <div class="line" data-i="2"><span class="k">Revenue</span><span class="v">AED 2,180</span></div>
          <div class="line" data-i="3"><span class="k">Busiest hour</span><span class="v">11 AM · 5 bookings</span></div>
          <div class="line" data-i="4"><span class="k mono" style="color:var(--mint-300)">Eloquent</span><span class="v" style="color:var(--text-2);font-family:inherit">Want me to fill the evening?<span class="cursor"></span></span></div>
        </div>
      </div>
    </div>
  </section>
    </div>

    <div class="hero-slide">
  <section class="hero">
    <div class="wrap hero-grid">
      <div>
        <span class="eyebrow"><span class="ping"></span> Lead-finding Lens · Built in the UAE</span>
        <h1>Find your next customer.<br><span class="accent">Before your competitor does.</span></h1>
        <p class="sub">Search any area and category — get real businesses with live contacts, ranked and ready to reach. Your team stops guessing and starts closing.</p>
        <div class="hero-actions">
          <a class="btn btn-primary" href="https://wa.me/971557369629?text=Hi%2C%20I%27d%20like%20a%20quick%20demo%20of%20Eloquent%20Business%20Hunt">
            Book a demo
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
          </a>
        </div>
        <div class="hero-note">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
          Real businesses · Live contacts · Export in one click
        </div>
      </div>

      <div class="demo" id="lp-hunt-demo">
        <div class="demo-top">
          <span class="who">
            <span class="av"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg></span>
            Lead search
          </span>
          <span class="badge"><span class="rec"></span> 128 found</span>
        </div>

        <div class="mic">
          <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg></span>
          <span style="font-size:14px;color:var(--text-1);font-weight:500">AC repair · Dubai Marina</span>
        </div>

        <p class="q">Searching: <b>AC repair · Dubai Marina</b></p>

        <div class="answer" aria-live="polite">
          <div class="line" data-i="0"><span class="k">Cool Air Technical</span><span class="v">+971 4 399 2210</span></div>
          <div class="line" data-i="1"><span class="k">Marina Chill AC</span><span class="v">+971 50 118 4403</span></div>
          <div class="line" data-i="2"><span class="k">SwiftCool Services</span><span class="v">+971 55 720 9188</span></div>
          <div class="line" data-i="3"><span class="k">Emirates FrostAir</span><span class="v">+971 4 552 0766</span></div>
          <div class="line" data-i="4"><span class="k mono" style="color:var(--mint-300)">Business Hunt</span><span class="v" style="color:var(--text-2);font-family:inherit">Export all 128 leads →<span class="cursor"></span></span></div>
        </div>
      </div>
    </div>
  </section>
    </div>
   </div>

   <div class="wrap slider-nav">
     <button class="slider-arrow" data-dir="-1" aria-label="Previous product">
       <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M15 18l-6-6 6-6"/></svg>
     </button>
     <div class="slider-dots" role="tablist" aria-label="Products">
       <button class="active" type="button" aria-label="Business Hunt"></button>
       <button type="button" aria-label="Business Lens"></button>
     </div>
     <button class="slider-arrow" data-dir="1" aria-label="Next product">
       <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 18l6-6-6-6"/></svg>
     </button>
   </div>
  </div>

  <section id="how">
    <div class="wrap">
      <div class="sec-head">
        <div class="sec-eyebrow">How it works</div>
        <h2>Three steps. No new habits.</h2>
        <p>Put a QR on your shopfront, your Instagram, or your WhatsApp. Everything after that runs itself.</p>
      </div>
      <div class="steps">
        <div class="step">
          <div class="rail"></div>
          <div class="no">01</div>
          <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M20 20h.01M17 20h.01M20 17h.01"/></svg></div>
          <h3>Share your QR or link</h3>
          <p>One scan opens your booking page. No app to download, nothing for the customer to install.</p>
        </div>
        <div class="step">
          <div class="rail"></div>
          <div class="no">02</div>
          <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/><rect x="7" y="7" width="10" height="10" rx="3"/><circle cx="10" cy="11" r="1"/><circle cx="14" cy="11" r="1"/></svg></div>
          <h3>AI takes the booking</h3>
          <p>It picks the slot, confirms the customer, and blocks your calendar — in their language, day or night.</p>
        </div>
        <div class="step">
          <div class="no">03</div>
          <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg></div>
          <h3>Manage by voice</h3>
          <p>Ask what happened today, move a booking, or pull a report — just by talking. No dashboards to dig through.</p>
        </div>
      </div>
    </div>
  </section>

  <section id="why">
    <div class="wrap">
      <div class="sec-head">
        <div class="sec-eyebrow">Why providers switch</div>
        <h2>Stop running your bookings from your phone's notifications.</h2>
      </div>
      <div class="props">
        <div class="prop">
          <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M20 20h.01M17 20h.01"/></svg></div>
          <h3>Book by QR</h3>
          <p>No more back-and-forth on WhatsApp. Customers scan, pick a time, done — even while you're working.</p>
          <div class="tag">→ zero install for customers</div>
        </div>
        <div class="prop">
          <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="7" y="7" width="10" height="10" rx="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/><circle cx="10" cy="11" r="1"/><circle cx="14" cy="11" r="1"/></svg></div>
          <h3>AI handles it</h3>
          <p>Scheduling, confirmations, reminders and reschedules — the AI does the admin so you don't touch it.</p>
          <div class="tag">→ works 24/7, in English & Arabic</div>
        </div>
        <div class="prop">
          <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg></div>
          <h3>Reports by voice</h3>
          <p>"How many bookings today?" "What did we make this week?" Ask out loud, hear the answer. No spreadsheets.</p>
          <div class="tag">→ the part nobody else has</div>
        </div>
      </div>
    </div>
  </section>

  <!-- TEMPORARILY HIDDEN: "Who it's for" section. Remove the hidden attribute to restore. -->
  <section class="who-sec" hidden>
    <div class="wrap">
      <div class="sec-head">
        <div class="sec-eyebrow">Who it's for</div>
        <h2>Any small service business that takes bookings.</h2>
        <p>If people book your time, Eloquent Business Lens runs it for you.</p>
      </div>
      <div class="chips">
        <span class="chip"><span class="d"></span>Home services</span>
        <span class="chip"><span class="d"></span>AC repair</span>
        <span class="chip"><span class="d"></span>Cleaning</span>
        <span class="chip"><span class="d"></span>Barber shops</span>
        <span class="chip"><span class="d"></span>Salons</span>
        <span class="chip"><span class="d"></span>Laundry</span>
        <span class="chip"><span class="d"></span>Spas & beauty</span>
        <span class="chip"><span class="d"></span>Maintenance</span>
      </div>
    </div>
  </section>

  <section>
    <div class="wrap">
      <div class="cta-band">
        <h2>Your next booking is one scan away.</h2>
        <p>Set up in minutes. First month free — no card, no commitment.</p>
        <div class="hero-actions">
          <a class="btn btn-primary" href="https://admin.eloquentservice.com">
            Start free
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
          </a>
          <a class="btn btn-secondary" href="https://wa.me/971557369629?text=Hi%2C%20I%27d%20like%20a%20quick%20demo%20of%20Eloquent%20Business%20Lens">Book a 10-min demo</a>
        </div>
      </div>
    </div>
  </section>
</main>

<footer>
  <div class="wrap foot">
    <small>Eloquent Business Lens — a product of Eloquent FZE LLC · Sharjah, UAE</small>
    <div class="links">
      <a href="https://admin.eloquentservice.com">Log in</a>
      <a href="https://wa.me/971557369629">WhatsApp</a>
      <a href="https://eloquentservice.com">Eloquent FZE</a>
    </div>
  </div>
</footer>
`;

export default function Web() {
  const hostRef = useRef<HTMLDivElement>(null);

  // Inject the landing's global CSS only while mounted; restore on unmount.
  useEffect(() => {
    const style = document.createElement('style');
    style.id = 'lp-styles';
    style.textContent = LP_CSS;
    document.head.appendChild(style);
    const prevTitle = document.title;
    document.title = 'Eloquent Business Lens — Take bookings by QR. Let AI handle them.';
    return () => {
      style.remove();
      document.title = prevTitle;
    };
  }, []);

  // Signature demo: cycle the voice question -> reveal report lines.
  useEffect(() => {
    const root = hostRef.current;
    if (!root) return;

    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const lines = Array.from(root.querySelectorAll<HTMLElement>('#lp-demo .answer .line'));
    const mic = root.querySelector<HTMLElement>('#lp-mic');
    const qtext = root.querySelector<HTMLElement>('#lp-qtext');
    const demo = root.querySelector<HTMLElement>('#lp-demo');
    const questions = ['"How did we do today?"', '"Any free slots this evening?"', '"What did we make this week?"'];
    let qi = 0;
    let cancelled = false;
    const timers: number[] = [];
    const wait = (fn: () => void, ms: number) => { timers.push(window.setTimeout(fn, ms)); };

    if (reduce) {
      lines.forEach((l) => l.classList.add('show'));
      return;
    }

    const reveal = (i: number) => {
      if (cancelled) return;
      if (i >= lines.length) { wait(loop, 2600); return; }
      lines[i].classList.add('show');
      wait(() => reveal(i + 1), 520);
    };
    function loop() {
      if (cancelled) return;
      lines.forEach((l) => l.classList.remove('show'));
      qi = (qi + 1) % questions.length;
      if (qtext) qtext.textContent = questions[qi];
      mic?.classList.remove('paused');
      wait(() => { mic?.classList.add('paused'); reveal(0); }, 1100);
    }
    const start = () => { wait(() => { mic?.classList.add('paused'); reveal(0); }, 1200); };

    let io: IntersectionObserver | null = null;
    if ('IntersectionObserver' in window && demo) {
      io = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting) { start(); io?.disconnect(); }
      }, { threshold: 0.3 });
      io.observe(demo);
    } else {
      start();
    }

    return () => {
      cancelled = true;
      timers.forEach((t) => clearTimeout(t));
      io?.disconnect();
    };
  }, []);

  // Hero product slider: Business Hunt shown first, auto-advancing every 3s.
  // Autoplay pauses on hover/focus and when the tab is hidden; any manual nav
  // (arrows / dots / keyboard / swipe) resets the timer. Disabled entirely
  // under prefers-reduced-motion.
  useEffect(() => {
    const root = hostRef.current;
    if (!root) return;
    const slider = root.querySelector<HTMLElement>('.hero-slider');
    const track = root.querySelector<HTMLElement>('#lp-track');
    const slides = Array.from(root.querySelectorAll<HTMLElement>('.hero-slide'));
    const dots = Array.from(root.querySelectorAll<HTMLButtonElement>('.slider-dots button'));
    const arrows = Array.from(root.querySelectorAll<HTMLButtonElement>('.slider-arrow'));
    const huntLines = Array.from(root.querySelectorAll<HTMLElement>('#lp-hunt-demo .line'));
    if (!track || slides.length === 0) return;

    // Display order (left→right, matching the dots): Business Hunt first, then
    // Business Lens. Values index into the DOM `slides` list (Lens=0, Hunt=1).
    const order = [1, 0];
    const HUNT_DOM = 1;
    const AUTO_MS = 3000;

    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const huntTimers: number[] = [];
    let pos = 0;
    let huntRevealed = false;
    let auto: number | undefined;

    const revealHunt = () => {
      if (huntRevealed) return;
      huntRevealed = true;
      if (reduce) { huntLines.forEach((l) => l.classList.add('show')); return; }
      huntLines.forEach((l, i) =>
        huntTimers.push(window.setTimeout(() => l.classList.add('show'), 300 + i * 480)),
      );
    };

    const render = () => {
      const dom = order[pos];
      track.style.transform = `translateX(-${dom * 100}%)`;
      dots.forEach((d, i) => d.classList.toggle('active', i === pos));
      slides.forEach((s, i) => s.setAttribute('aria-hidden', String(i !== dom)));
      if (dom === HUNT_DOM) revealHunt();
    };
    const go = (n: number) => { pos = (n + order.length) % order.length; render(); };

    const stopAuto = () => { if (auto !== undefined) { clearInterval(auto); auto = undefined; } };
    const startAuto = () => {
      if (reduce || order.length < 2) return;
      stopAuto();
      auto = window.setInterval(() => go(pos + 1), AUTO_MS);
    };
    // Manual nav resets the timer so the chosen slide gets a full dwell.
    const nav = (n: number) => { go(n); startAuto(); };

    const onArrow = (e: Event) => {
      const dir = Number((e.currentTarget as HTMLElement).dataset.dir || '1');
      nav(pos + dir);
    };
    const dotHandlers = dots.map((_, i) => () => nav(i));
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'ArrowLeft') nav(pos - 1);
      else if (e.key === 'ArrowRight') nav(pos + 1);
    };
    let x0: number | null = null;
    const onTouchStart = (e: TouchEvent) => { x0 = e.touches[0].clientX; };
    const onTouchEnd = (e: TouchEvent) => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0;
      if (Math.abs(dx) > 45) nav(pos + (dx < 0 ? 1 : -1));
      x0 = null;
    };
    const onVisibility = () => { if (document.hidden) stopAuto(); else startAuto(); };

    arrows.forEach((a) => a.addEventListener('click', onArrow));
    dots.forEach((d, i) => d.addEventListener('click', dotHandlers[i]));
    root.addEventListener('keydown', onKey);
    track.addEventListener('touchstart', onTouchStart, { passive: true });
    track.addEventListener('touchend', onTouchEnd, { passive: true });
    slider?.addEventListener('pointerenter', stopAuto);
    slider?.addEventListener('pointerleave', startAuto);
    document.addEventListener('visibilitychange', onVisibility);

    render();
    startAuto();

    return () => {
      arrows.forEach((a) => a.removeEventListener('click', onArrow));
      dots.forEach((d, i) => d.removeEventListener('click', dotHandlers[i]));
      root.removeEventListener('keydown', onKey);
      track.removeEventListener('touchstart', onTouchStart);
      track.removeEventListener('touchend', onTouchEnd);
      slider?.removeEventListener('pointerenter', stopAuto);
      slider?.removeEventListener('pointerleave', startAuto);
      document.removeEventListener('visibilitychange', onVisibility);
      stopAuto();
      huntTimers.forEach((t) => clearTimeout(t));
    };
  }, []);

  return <div className="lp-scope" ref={hostRef} dangerouslySetInnerHTML={{ __html: LP_HTML }} />;
}
