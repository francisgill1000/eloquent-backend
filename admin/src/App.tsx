import { Routes, Route } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { SubscriptionProvider } from '@/context/SubscriptionContext';
import RequireSubscription from '@/components/RequireSubscription';
import Subscribe from '@/pages/Subscribe';
import { ParticleField } from '@/components/ParticleField';
import { MobileLayout } from '@/layout/MobileLayout';
import { AppShell } from '@/layout/AppShell';
import { RequireShop } from '@/layout/RequireShop';
import { ModuleGuard } from '@/components/ModuleGuard';
import Login from '@/pages/Login';
import Web from '@/pages/Web';
import ScanApprove from '@/pages/ScanApprove';
import Bookings from '@/pages/Bookings';
import BookingAction from '@/pages/BookingAction';
import BookingPreview from '@/pages/BookingPreview';
import Reminders from '@/pages/Reminders';
import Services from '@/pages/Services';
import ServiceEdit from '@/pages/ServiceEdit';
import Categories from '@/pages/Categories';
import CategoryEdit from '@/pages/CategoryEdit';
import Staff from '@/pages/Staff';
import StaffAvailability from '@/pages/StaffAvailability';
import RecurringBooking from '@/pages/RecurringBooking';
import Customers from '@/pages/Customers';
import CustomerDetail from '@/pages/CustomerDetail';
import Reviews from '@/pages/Reviews';
import Insights from '@/pages/Insights';
import AiSummary from '@/pages/AiSummary';
import BookingNotifications from '@/pages/BookingNotifications';
import WorkingHours from '@/pages/WorkingHours';
import Profile from '@/pages/Profile';
import Settings from '@/pages/Settings';
import AccessControl from '@/pages/AccessControl';
import MasterShops from '@/pages/MasterShops';
import MasterPricing from '@/pages/MasterPricing';
import MasterShopCreate from '@/pages/MasterShopCreate';
import MasterShopDetail from '@/pages/MasterShopDetail';
import CategorySetup from '@/pages/CategorySetup';
import Assistant from '@/pages/Assistant';
import VoiceAssistant from '@/pages/VoiceAssistant';
import { Landing } from '@/components/Landing';
import Conversations from '@/pages/Conversations';
import Chats from '@/pages/Chats';
import ChatThread from '@/pages/ChatThread';
import WhatsAppSetup from '@/pages/WhatsAppSetup';
import Leads from '@/pages/Leads';
import LeadCredits from '@/pages/LeadCredits';
import LeadDetail from '@/pages/LeadDetail';
import SimulationSettings from '@/pages/SimulationSettings';
import PublicBooking from '@/pages/PublicBooking';
import { RequirePerm } from '@/components/RequirePerm';

export default function App() {
  return (
    <ShopProvider>
      <SubscriptionProvider>
      <ParticleField />
      <Routes>
        {/* Public / full-screen */}
        <Route path="/web" element={<Web />} />
        <Route path="/hunt" element={<Web variant="hunt" />} />
        <Route path="/lens" element={<Web variant="lens" />} />
        <Route path="/login" element={<Login />} />
        <Route path="/scan/:token" element={<ScanApprove />} />
        <Route path="/book/:shopId" element={<PublicBooking />} />

        {/* Authenticated — wrapped in the desktop AppShell (sidebar at ≥1024px) */}
        <Route element={<RequireShop />}>
          {/* Paywall: logged-in but NOT subscription-gated, so lapsed shops can pay. */}
          <Route path="/subscribe" element={<Subscribe />} />
          <Route element={<AppShell />}>
          <Route element={<RequireSubscription />}>
          <Route path="/booking/preview" element={<BookingPreview />} />
          <Route path="/booking/:id" element={<BookingAction />} />
          <Route element={<RequirePerm perm="services.view" />}>
            <Route path="/services/new" element={<ServiceEdit />} />
            <Route path="/services/:id/edit" element={<ServiceEdit />} />
            <Route path="/categories" element={<Categories />} />
            <Route path="/categories/new" element={<CategoryEdit />} />
            <Route path="/categories/:id/edit" element={<CategoryEdit />} />
          </Route>
          <Route element={<RequirePerm perm="staff.view" />}>
            <Route path="/staff" element={<Staff />} />
            <Route path="/staff/:id/availability" element={<StaffAvailability />} />
          </Route>
          <Route element={<RequirePerm perm="bookings.view" />}>
            <Route path="/bookings/recurring" element={<RecurringBooking />} />
          </Route>
          <Route element={<ModuleGuard module="bookings" />}>
            <Route element={<RequirePerm perm="customers.view" />}>
              <Route path="/customers" element={<Customers />} />
              <Route path="/customers/:id" element={<CustomerDetail />} />
            </Route>
          </Route>
          <Route path="/reviews" element={<Reviews />} />
          <Route element={<RequirePerm perm="reports.view" />}>
            <Route path="/insights" element={<Insights />} />
          </Route>
          <Route element={<ModuleGuard module="leads" />}>
            {/* Mirrors the backend: reaching any Hunt screen needs leads.view;
                the buy action on the credits page needs leads.purchase on top
                (enforced there and by POST /shop/leads/purchase). */}
            <Route element={<RequirePerm perm="leads.view" />}>
              <Route path="/leads" element={<Leads />} />
              <Route path="/leads/:id" element={<LeadDetail />} />
              <Route path="/leads/credits" element={<LeadCredits />} />
            </Route>
          </Route>
          <Route element={<RequirePerm perm={['users.view', 'roles.view']} />}>
            <Route path="/settings/access" element={<AccessControl />} />
          </Route>
          <Route element={<RequirePerm perm="settings.manage" />}>
            <Route path="/settings/simulation" element={<SimulationSettings />} />
            <Route path="/settings/notifications" element={<BookingNotifications />} />
          </Route>
          <Route element={<RequirePerm perm="working_hours.view" />}>
            <Route path="/working-hours" element={<WorkingHours />} />
          </Route>
          <Route path="/category-setup" element={<CategorySetup />} />
          <Route element={<RequirePerm perm="assistant.manage" />}>
            <Route path="/assistant" element={<Assistant />} />
          </Route>
          <Route path="/master" element={<MasterShops />} />
          <Route path="/master/pricing" element={<MasterPricing />} />
          <Route path="/master/new" element={<MasterShopCreate />} />
          <Route path="/master/:id" element={<MasterShopDetail />} />
          <Route path="/chats/setup" element={<WhatsAppSetup />} />
          <Route path="/chats/:id" element={<ChatThread />} />

          {/* Authenticated tabbed */}
          <Route element={<MobileLayout />}>
            {/* The Ask assistant is the home screen; /ask stays as an alias so
                old links/bookmarks keep working. Both sit inside the tabbed
                layout so the bottom bar stays visible. */}
            <Route element={<RequirePerm perm="assistant.use" />}>
              <Route path="/" element={<Landing />} />
              <Route path="/ask" element={<VoiceAssistant />} />
              <Route path="/ask/:conversationId" element={<VoiceAssistant />} />
            </Route>
            {/* Full-page list of the shop's Ask conversations (sits below Home in the nav). */}
            <Route element={<RequirePerm perm="chats.view" />}>
              <Route path="/conversations" element={<Conversations />} />
            </Route>
            <Route element={<RequirePerm perm="summary.view" />}>
              <Route path="/ai-summary" element={<AiSummary />} />
            </Route>
            <Route element={<RequirePerm perm="bookings.view" />}>
              <Route path="/bookings" element={<Bookings />} />
              <Route path="/reminders" element={<Reminders />} />
            </Route>
            <Route path="/chats" element={<Chats />} />
            <Route element={<RequirePerm perm="services.view" />}>
              <Route path="/services" element={<Services />} />
            </Route>
            <Route path="/settings" element={<Settings />} />
            <Route element={<RequirePerm perm="profile.view" />}>
              <Route path="/profile" element={<Profile />} />
            </Route>
          </Route>
          </Route>
          </Route>
        </Route>
      </Routes>
      </SubscriptionProvider>
    </ShopProvider>
  );
}
