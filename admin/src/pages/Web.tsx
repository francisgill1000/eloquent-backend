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
      Eloquent <span style="font-weight:400;color:var(--text-2)">Booking&nbsp;Manager</span>
    </div>
    <nav class="nav-cta">
      <a class="nav-login" href="https://admin.eloquentservice.com">Log in</a>
      <a class="btn btn-primary" href="https://admin.eloquentservice.com">Start free</a>
    </nav>
  </div>
</header>

<main>
  <section class="hero">
    <div class="wrap hero-grid">
      <div>
        <span class="eyebrow"><span class="ping"></span> AI booking manager · Built in the UAE</span>
        <h1>Take bookings by QR.<br><span class="accent">Let AI handle them.</span><br>Manage your day by voice.</h1>
        <p class="sub">Your customers scan and book in seconds. AI does the scheduling. You run the whole thing — even your reports — just by asking out loud.</p>
        <div class="hero-actions">
          <a class="btn btn-primary" href="https://admin.eloquentservice.com">
            Start free
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
          </a>
          <a class="btn btn-secondary" href="https://wa.me/971557369629?text=Hi%2C%20I%27d%20like%20a%20quick%20demo%20of%20Eloquent%20Booking%20Manager">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-2.8.7.8-2.7-.2-.3A8 8 0 1 1 12 20Zm4.4-6c-.2-.1-1.4-.7-1.6-.8s-.4-.1-.5.1-.6.8-.8 1-.3.2-.5.1a6.6 6.6 0 0 1-3.3-2.9c-.2-.4.2-.4.6-1.2a.4.4 0 0 0 0-.4c0-.1-.5-1.3-.7-1.7s-.4-.4-.5-.4h-.5a.9.9 0 0 0-.7.3 2.8 2.8 0 0 0-.9 2.1 4.9 4.9 0 0 0 1 2.6 11 11 0 0 0 4.3 3.8c1.6.7 2 .6 2.4.5a2.4 2.4 0 0 0 1.6-1.1 2 2 0 0 0 .1-1.1c0-.1-.2-.2-.5-.3Z"/></svg>
            Chat on WhatsApp
          </a>
        </div>
        <div class="hero-note">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
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
        <p>If people book your time, Eloquent Booking Manager runs it for you.</p>
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
          <a class="btn btn-secondary" href="https://wa.me/971557369629?text=Hi%2C%20I%27d%20like%20a%20quick%20demo%20of%20Eloquent%20Booking%20Manager">Book a 10-min demo</a>
        </div>
      </div>
    </div>
  </section>
</main>

<footer>
  <div class="wrap foot">
    <small>Eloquent Booking Manager — a product of Eloquent FZE LLC · Sharjah, UAE</small>
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
    document.title = 'Eloquent Booking Manager — Take bookings by QR. Let AI handle them.';
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
    const lines = Array.from(root.querySelectorAll<HTMLElement>('.answer .line'));
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

  return <div className="lp-scope" ref={hostRef} dangerouslySetInnerHTML={{ __html: LP_HTML }} />;
}
