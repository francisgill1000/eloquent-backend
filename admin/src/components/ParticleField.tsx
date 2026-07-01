import { useEffect, useRef } from 'react';

type Pt = { x: number; y: number; vx: number; vy: number; r: number };

/**
 * Animated particle-constellation background, ported from the AI Coordinator
 * app. A fixed, full-screen canvas of drifting teal dots that link with faint
 * lines when they get close. It sits behind the UI (z-index below the app),
 * is disabled when the user prefers reduced motion, and pauses while the tab
 * is hidden so it costs nothing in the background.
 */
export function ParticleField() {
  const ref = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const cv = ref.current;
    if (!cv || reduce) return;
    const ctx = cv.getContext('2d');
    if (!ctx) return;

    let w = 0, h = 0, dpr = 1;
    let pts: Pt[] = [];
    let raf = 0;

    function size() {
      dpr = Math.min(window.devicePixelRatio || 1, 2);
      w = cv!.width = window.innerWidth * dpr;
      h = cv!.height = window.innerHeight * dpr;
      cv!.style.width = window.innerWidth + 'px';
      cv!.style.height = window.innerHeight + 'px';
      const target = Math.min(120, Math.floor((window.innerWidth * window.innerHeight) / 13000));
      pts = Array.from({ length: target }, () => ({
        x: Math.random() * w,
        y: Math.random() * h,
        vx: (Math.random() - 0.5) * 0.75 * dpr,
        vy: (Math.random() - 0.5) * 0.75 * dpr,
        r: (Math.random() * 1.7 + 0.8) * dpr,
      }));
    }

    function step() {
      ctx!.clearRect(0, 0, w, h);
      const LINK = 165 * dpr;
      for (let i = 0; i < pts.length; i++) {
        const p = pts[i];
        p.x += p.vx; p.y += p.vy;
        if (p.x < 0 || p.x > w) p.vx *= -1;
        if (p.y < 0 || p.y > h) p.vy *= -1;
        ctx!.beginPath();
        ctx!.arc(p.x, p.y, p.r, 0, 7);
        ctx!.fillStyle = 'rgba(0,255,204,0.85)';
        ctx!.fill();
        for (let j = i + 1; j < pts.length; j++) {
          const q = pts[j];
          const dx = p.x - q.x, dy = p.y - q.y, d = Math.hypot(dx, dy);
          if (d < LINK) {
            ctx!.beginPath();
            ctx!.moveTo(p.x, p.y);
            ctx!.lineTo(q.x, q.y);
            ctx!.strokeStyle = 'rgba(0,255,204,' + 0.3 * (1 - d / LINK) + ')';
            ctx!.lineWidth = 1.1 * dpr;
            ctx!.stroke();
          }
        }
      }
      raf = requestAnimationFrame(step);
    }

    let t: number | undefined;
    const onResize = () => { window.clearTimeout(t); t = window.setTimeout(size, 200); };
    const onVis = () => {
      if (document.hidden) cancelAnimationFrame(raf);
      else { cancelAnimationFrame(raf); step(); }
    };
    window.addEventListener('resize', onResize);
    document.addEventListener('visibilitychange', onVis);
    size();
    step();

    return () => {
      cancelAnimationFrame(raf);
      window.removeEventListener('resize', onResize);
      document.removeEventListener('visibilitychange', onVis);
      window.clearTimeout(t);
    };
  }, []);

  return <canvas ref={ref} className="fx-canvas" aria-hidden="true" />;
}
