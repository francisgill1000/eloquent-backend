export type WorkingHours = { start_time?: string; end_time?: string };

export type Service = {
  id: number;
  title?: string;
  name?: string;
  description?: string;
  price?: number | string;
  image?: string;
  parent_category?: { id: number; name: string; image?: string | null } | null;
};

export type Shop = {
  id: number;
  name: string;
  logo?: string;
  hero_image?: string;
  location?: string;
  phone?: string;
  shop_code?: string;
  rating?: number | string;
  distance?: string;
  distance_km?: number | string;
  is_open?: boolean;
  is_favourite?: boolean;
  today_working_hours?: WorkingHours;
  catalogs?: Service[];
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
  customer_name?: string;
  customer?: { name?: string };
  shop?: { name?: string; location?: string };
  services?: Service[];
  invoice?: BookingInvoice | null;
};

export type BookingInvoice = {
  id: number;
  invoice_number?: string;
  total?: number | string;
  status?: 'issued' | 'paid' | 'cancelled' | string;
  paid_at?: string | null;
};

/** Live Chat message. Direction is from the shop's side: 'in' = sent by me (the customer). */
export type ChatMessage = {
  id: number;
  direction: 'in' | 'out';
  type?: string;
  body: string;
  media_url?: string | null;
  created_at?: string;
};

export type Paginated<T> = {
  data: T[];
  current_page?: number;
  last_page?: number;
};
