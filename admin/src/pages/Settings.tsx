import { Link } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { usePush } from '@/lib/usePush';
import { visibleSettingsOptions, type SettingsOption } from '@/lib/nav';
import type { Module } from '@/lib/modules';

const BOTH: Module[] = ['bookings', 'leads'];

export default function Settings() {
  const { shop, can } = useShop();
  const push = usePush();
  // Every option is gated by its product module AND its view permission
  // (see lib/nav.ts); Access Control shows for users with users.view or roles.view.
  const visible = visibleSettingsOptions(shop, can);
  const options: SettingsOption[] = shop?.is_master
    ? [...visible, { label: 'All Businesses', sub: 'Master view — codes, PINs & activity', to: '/master', icon: 'Key', modules: BOTH }]
    : visible;

  return (
    <div className="m-screen c-settings"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">Settings</h1>
        <p className="c-page-sub">Manage how your business runs on Business Lens.</p>
      </div>

      <div className="c-set-grid">
      {push.supported && (
        <div
          className="c-set-link"
          role="button"
          tabIndex={0}
          aria-pressed={push.on}
          style={{ cursor: push.busy ? 'default' : 'pointer', opacity: push.busy ? 0.7 : 1 }}
          onClick={() => { if (!push.busy) void push.toggle(); }}
          onKeyDown={(e) => { if ((e.key === 'Enter' || e.key === ' ') && !push.busy) { e.preventDefault(); void push.toggle(); } }}
        >
          <span className="c-set-ic"><Icons.Bell size={18} /></span>
          <span className="c-set-body">
            <span className="c-set-label">Notifications</span>
            <span className="c-set-sub">{push.busy ? 'Updating…' : push.on ? 'On — you’ll be alerted to new chats' : 'Off — tap to get alerts for new leads'}</span>
          </span>
          <span className={`c-toggle ${push.on ? 'on' : ''}`}><span className="c-toggle-knob" /></span>
        </div>
      )}

      {options.map((o) => {
        const Icon = Icons[o.icon];
        return (
          <Link key={o.to} to={o.to} className="c-set-link">
            <span className="c-set-ic"><Icon size={18} /></span>
            <span className="c-set-body">
              <span className="c-set-label">{o.label}</span>
              <span className="c-set-sub">{o.sub}</span>
            </span>
            <Icons.Chevron size={18} />
          </Link>
        );
      })}
      </div>
    </div></div>
  );
}
