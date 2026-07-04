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
  bookings_count?: number;
  wa_connected?: boolean;
  wa_number?: string | null;
  last_login_at?: string | null;
  created_at?: string | null;
};
