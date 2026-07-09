type P = { size?: number };
const base = {
  fill: 'none', stroke: 'currentColor', strokeWidth: 1.8,
  strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const,
};

export const Icons = {
  Home: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M3 11l9-8 9 8" /><path d="M5 10v10h14V10" /></svg>
  ),
  Speaker: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M4 9v6h4l5 4V5L8 9H4z" /><path d="M16 8.5a4 4 0 0 1 0 7" /><path d="M18.5 6a7 7 0 0 1 0 12" /></svg>
  ),
  Stop: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><rect x="6" y="6" width="12" height="12" rx="2" /></svg>
  ),
  Calendar: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><rect x="3" y="4" width="18" height="17" rx="2" /><path d="M3 9h18M8 2v4M16 2v4" /></svg>
  ),
  Heart: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M12 21s-7-4.5-9.5-9A4.5 4.5 0 0 1 12 6a4.5 4.5 0 0 1 9.5 6c-2.5 4.5-9.5 9-9.5 9z" /></svg>
  ),
  HeartFilled: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-7-4.5-9.5-9A4.5 4.5 0 0 1 12 6a4.5 4.5 0 0 1 9.5 6c-2.5 4.5-9.5 9-9.5 9z" /></svg>
  ),
  MapPin: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M12 22s-7-7.6-7-13a7 7 0 0 1 14 0c0 5.4-7 13-7 13z" /><circle cx="12" cy="9" r="2.5" /></svg>
  ),
  User: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>
  ),
  Search: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>
  ),
  Locate: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><circle cx="12" cy="12" r="3" /><path d="M12 2v3M12 19v3M2 12h3M19 12h3" /></svg>
  ),
  Chevron: ({ size = 20 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M9 6l6 6-6 6" /></svg>
  ),
  ChevronLeft: ({ size = 20 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M15 6l-6 6 6 6" /></svg>
  ),
  Clock: ({ size = 14 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
  ),
  Check: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><circle cx="12" cy="12" r="9" /><path d="M8 12l3 3 5-6" /></svg>
  ),
  Logout: ({ size = 20 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="M16 17l5-5-5-5M21 12H9" /></svg>
  ),
  WhatsApp: ({ size = 20 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.8 4.9-1.3A10 10 0 1 0 12 2zm5.3 14.1c-.2.6-1.2 1.2-1.7 1.2-.4 0-1 .1-3.3-.9-2.8-1.2-4.5-4-4.7-4.2-.1-.2-1-1.4-1-2.6s.6-1.8.9-2.1c.2-.2.4-.3.6-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.3 0 .5l-.4.6c-.2.2-.3.4-.1.6.2.4.9 1.4 1.9 2.2 1.2 1.1 2.1 1.4 2.4 1.5.2.1.4.1.5-.1l.7-.9c.2-.2.4-.2.6-.1l1.9.9c.3.1.5.2.5.4.1.2.1.8-.1 1.4z" /></svg>
  ),
  Sliders: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M4 6h16M4 12h16M4 18h16" /><circle cx="9" cy="6" r="2.4" fill="currentColor" stroke="none" /><circle cx="15" cy="12" r="2.4" fill="currentColor" stroke="none" /><circle cx="7" cy="18" r="2.4" fill="currentColor" stroke="none" /></svg>
  ),
  Chat: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M21 12a8 8 0 0 1-8 8H5l-2 2V12a8 8 0 0 1 8-8h2a8 8 0 0 1 8 8z" /><path d="M8 11h8M8 14.5h5" /></svg>
  ),
  Send: ({ size = 20 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M22 2L11 13" /><path d="M22 2l-7 20-4-9-9-4 20-7z" /></svg>
  ),
  Mic: ({ size = 20 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><rect x="9" y="2" width="6" height="12" rx="3" /><path d="M5 11a7 7 0 0 0 14 0" /><path d="M12 19v3" /></svg>
  ),
  Store: ({ size = 28 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M3 9l1.5-5h15L21 9" /><path d="M4 9v11h16V9" /><path d="M9 20v-6h6v6" /></svg>
  ),
  Sparkle: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M12 3l1.8 4.9L18.5 9l-4.7 1.8L12 15l-1.8-4.2L5.5 9l4.7-1.1L12 3z" /><path d="M18 15l.7 1.9 1.8.6-1.8.7L18 20l-.7-1.8-1.8-.7 1.8-.6L18 15z" /></svg>
  ),
  Bell: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.7 21a2 2 0 0 1-3.4 0" /></svg>
  ),
  Grid: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg>
  ),
  List: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01" /></svg>
  ),
  Chart: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M3 3v18h18" /><rect x="7" y="12" width="3" height="6" rx="1" /><rect x="12.5" y="8" width="3" height="10" rx="1" /><rect x="18" y="5" width="3" height="13" rx="1" /></svg>
  ),
  Share: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4" /></svg>
  ),
  Copy: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>
  ),
  Download: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M12 3v12" /><path d="M7 11l5 5 5-5" /><path d="M5 21h14" /></svg>
  ),
  Tag: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0l-7-7A2 2 0 0 1 3 12.2V5a2 2 0 0 1 2-2h7.2a2 2 0 0 1 1.4.6l7 7a2 2 0 0 1 0 2.8z" /><circle cx="7.5" cy="7.5" r="1.3" /></svg>
  ),
  Key: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><circle cx="8" cy="8" r="5" /><path d="M11.5 11.5L21 21M17 17l2-2M14 14l2-2" /></svg>
  ),
  Image: ({ size = 24 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="M21 15l-5-5L5 21" /></svg>
  ),
  Trash: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M6 6l1 14a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-14" /></svg>
  ),
  Edit: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>
  ),
  ArrowRight: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M5 12h14M13 6l6 6-6 6" /></svg>
  ),
  Plus: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M12 5v14M5 12h14" /></svg>
  ),
  Power: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M12 3v9" /><path d="M6.5 7a8 8 0 1 0 11 0" /></svg>
  ),
  Users: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><circle cx="9" cy="8" r="3.5" /><path d="M3 21a6 6 0 0 1 12 0" /><path d="M16 5.5a3.5 3.5 0 0 1 0 6.8M21 21a6 6 0 0 0-4.5-5.8" /></svg>
  ),
  Phone: ({ size = 18 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z" /></svg>
  ),
};
