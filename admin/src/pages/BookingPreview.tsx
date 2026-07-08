import { useLocation, useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import type { SimBooking } from '@/lib/simulation';

// Read-only booking payoff for the demo simulation. Renders from navigation
// state only — it never fetches or persists anything. Mirrors BookingAction's
// hero card so the recording ends on a screen identical to the real product.
export default function BookingPreview() {
  const navigate = useNavigate();
  const b = (useLocation().state as { booking?: SimBooking } | null)?.booking;

  if (!b) {
    return (
      <div className="m-screen"><div className="m-scroll">
        <button className="c-back" onClick={() => navigate('/ask')}><Icons.ChevronLeft size={16} /> Back</button>
        <p className="c-muted-center">No simulation to preview. Start one from Settings → Demo simulation.</p>
      </div></div>
    );
  }

  const name = b.customer_name || 'Guest';
  return (
    <div className="m-screen c-booking-action"><div className="m-scroll">
      <div className="ba-wrap">
        <button className="c-back" onClick={() => navigate('/ask?sim=1')}><Icons.ChevronLeft size={16} /> Back</button>

        <div className="ba-card ba-timeline-card">
          <ol className="ba-timeline">
            {[{ label: 'Queued', state: 'done' }, { label: 'Booked', state: 'current' }, { label: 'Completed', state: 'todo' }].map((step, i) => (
              <li key={step.label} className={`ba-tstep ${step.state}`}>
                <span className="ba-tstep-in">
                  <span className="ba-tnode">{step.state === 'done' ? <Icons.Check size={16} /> : i + 1}</span>
                  <span className="ba-tmeta">
                    <span className="ba-tlabel">{step.label}</span>
                    <span className="ba-tstate">{step.state === 'done' ? 'Done' : step.state === 'current' ? 'Current' : 'Pending'}</span>
                  </span>
                </span>
              </li>
            ))}
          </ol>
        </div>

        <div className="ba-card ba-hero">
          <div className="ba-hero-main">
            <div className="ba-hero-top">
              <div className="ba-avatar">{name.charAt(0).toUpperCase()}</div>
              <div className="ba-hero-id">
                <span className="ba-name">{name}</span>
                <span className="ba-ref">New booking</span>
              </div>
              <span className="c-chip c-chip-booked">Booked</span>
            </div>

            <div className="ba-service">
              <span className="ba-tile-l">Service</span>
              <span className="ba-service-val">{b.service || '—'}</span>
            </div>

            <div className="ba-grid">
              <div className="ba-tile"><Icons.Calendar size={15} /><span className="ba-tile-l">Date</span><span className="ba-tile-v">{b.date || '—'}</span></div>
              <div className="ba-tile"><Icons.Clock size={15} /><span className="ba-tile-l">Time</span><span className="ba-tile-v">{b.start_time || '—'}</span></div>
              <div className="ba-tile"><Icons.User size={15} /><span className="ba-tile-l">Staff</span><span className="ba-tile-v">{b.staff_name || 'Unassigned'}</span></div>
              <div className="ba-tile ba-tile-amount"><Icons.Tag size={15} /><span className="ba-tile-l">Charges</span><span className="ba-tile-v">AED {b.price || 0}</span></div>
            </div>
          </div>
        </div>
      </div>
    </div></div>
  );
}
