export type WorkingHours = {
  day_of_week?: number; // PHP date('w'): Sun=0 … Sat=6
  day?: string;
  start_time?: string;
  end_time?: string;
  slot_duration?: number;
  is_closed?: boolean;
};

export type Service = {
  id: number;
  title?: string;
  name?: string;
  price?: number | string;
  duration?: number | string;
  description?: string;
  image?: string;
  parent_category_id?: number | null;
  parent_category?: ParentCategory | null;
};

export type ParentCategory = {
  id: number;
  name: string;
  image?: string | null;
};

export type StaffMember = {
  id: number;
  name: string;
  is_active?: boolean;
};

export type Invoice = {
  id: number;
  status?: string;
  amount?: number | string;
  paid?: boolean;
};

export type ServiceCategory = {
  id: number;
  code?: string;
  name: string;
  icon?: string;
};

export type Shop = {
  id: number;
  name: string;
  shop_code?: string;
  category_id?: number;
  custom_category?: string | null;
  category_confirmed_at?: string | null;
  is_master?: boolean;
  logo?: string;
  hero_image?: string;
  location?: string;
  address?: string;
  phone?: string;
  email?: string;
  description?: string;
  latitude?: number | string;
  longitude?: number | string;
  is_open?: boolean;
  modules?: Array<'bookings' | 'leads'>;
  working_hours?: WorkingHours[];
  catalogs?: Service[];
  [key: string]: unknown;
};

export type Booking = {
  id: number;
  status?: string;
  date?: string;
  show_date?: string;
  start_time?: string;
  end_time?: string;
  charges?: number | string;
  booking_reference?: string;
  reminder_sent?: boolean;
  reminder_sent_at?: string | null;
  customer_name?: string;
  customer_whatsapp?: string;
  staff_id?: number | null;
  shop_id?: number;
  customer?: { name?: string; phone?: string };
  staff?: StaffMember | null;
  shop?: { id?: number; name?: string; location?: string };
  services?: Service[];
  invoice?: Invoice | null;
};

export type ShopBookingsResponse = {
  data: Booking[];
  total_bookings?: number;
  total_revenue?: number | string;
  current_page?: number;
  last_page?: number;
};

export type Paginated<T> = {
  data: T[];
  current_page?: number;
  last_page?: number;
};

export type WaAccountInfo = {
  connected: boolean;
  phone_number?: string | null;
  phone_number_id?: string;
  waba_id?: string | null;
  status?: string;
  token_preview?: string;
};

export type WaContact = {
  id: number;
  /** 'wa' = WhatsApp thread; 'app' = in-app Live Chat (no WA number). */
  channel?: 'wa' | 'app';
  wa_number?: string | null;
  name?: string | null;
  last_message_preview?: string | null;
  last_message_direction?: 'in' | 'out' | null;
  last_message_at?: string | null;
  unread_count?: number;
  /** false = a human took over; the AI concierge is paused for this thread. */
  ai_enabled?: boolean;
  /** Lead triage tag: hot|warm|cold|follow_up|not_interested. null = New/unset. */
  lead_status?: string | null;
};

export type WaMessage = {
  id: number;
  direction: 'in' | 'out';
  sender_type?: 'customer' | 'ai' | 'staff';
  type?: string;
  body: string;
  status?: string | null;
  media_url?: string | null;
  media_mime?: string | null;
  created_at?: string;
};

// ---- RBAC ----
export type AuthUser = { id: number | null; name: string; is_active: boolean };

export type Me = { user: AuthUser | null; permissions: string[] };

/** A permission module group from the read-only catalog. */
export type PermGroup = { label: string; permissions: Record<string, string> };

export type Role = {
  id: number;
  name: string;
  is_owner: boolean;
  permissions: string[];
};

export type ShopUser = {
  id: number;
  name: string;
  is_active: boolean;
  role: { id: number; name: string } | null;
};

export type MasterShop = {
  id: number;
  name: string;
  shop_code?: string;
  pin?: string;
  phone?: string | null;
  location?: string | null;
  category?: string | null;
  status?: string;
  persona?: string | null;
  is_master?: boolean;
  modules?: Array<'bookings' | 'leads'>;
  /** Business Hunt credit balance (independent of the Lens subscription). */
  hunt_credits?: number;
  subscription_status?: string | null;
  plan?: string | null;
  access_until?: string | null;
  days_left?: number;
  bookings_count?: number;
  wa_connected?: boolean;
  wa_number?: string | null;
  last_login_at?: string | null;
  created_at?: string | null;
};

// --- Lead Finder ---------------------------------------------------------

/** The fixed prospecting funnel — mirrors the backend Lead::STATUSES. */
export const LEAD_STATUSES = ['new', 'sent', 'replied', 'demo', 'won', 'pass'] as const;
export type LeadStatus = (typeof LEAD_STATUSES)[number];

/** A normalized search result from the discovery source (not yet saved). */
export type LeadResult = {
  name: string;
  phone?: string | null;
  website?: string | null;
  address?: string | null;
  category?: string | null;
  lat?: number | null;
  lng?: number | null;
  rating?: number | null;
  external_ref: string;
  source?: string;
  /** True for Meta Ad Library results (business actively running ads). */
  advertising?: boolean;
};

/** Which discovery source a search targets. */
export type LeadSource = 'google_places' | 'meta_ad_library';

export type LeadSearchMeta = {
  from_cache: boolean;
  /** Business Hunt credit balance after this search (cache hits don't spend one). */
  credits: number;
  /** The real business-type keyword the AI actually searched (from the user's text). */
  searched_for?: string;
};

/** A master-editable Business Hunt credit pack. price_fils is in fils (100 = AED 1). */
export type CreditPack = {
  id: number;
  name: string;
  credits: number;
  price_fils: number;
  active?: boolean;
  sort?: number;
};

export type LeadSearchResponse = {
  data: LeadResult[];
  meta: LeadSearchMeta;
};

/** A saved lead the shop is working. Server appends the *_url accessors. */
export type Lead = {
  id: number;
  name: string;
  phone?: string | null;
  whatsapp?: string | null;
  website?: string | null;
  address?: string | null;
  category?: string | null;
  lat?: number | null;
  lng?: number | null;
  source?: string;
  external_ref?: string | null;
  status: LeadStatus;
  notes?: string | null;
  last_contacted_at?: string | null;
  next_followup_at?: string | null;
  created_at?: string | null;
  // Appended accessors from the API
  whatsapp_url?: string | null;
  is_mobile?: boolean;
  tel_url?: string | null;
  map_url?: string | null;
  whatsapp_opening_url?: string | null;
  whatsapp_followup_url?: string | null;
};

export type LeadFunnel = Record<LeadStatus, number>;

export type LeadListResponse = {
  data: Lead[];
  funnel: LeadFunnel;
};

/** One row in a lead's activity history (status changes, notes, contacts). */
export type LeadActivity = {
  id: number;
  type: 'status_change' | 'note' | 'contacted' | string;
  payload?: { from?: string; to?: string; note?: string } | null;
  created_at?: string | null;
};

export type LeadDetailResponse = {
  lead: Lead;
  activities: LeadActivity[];
};
