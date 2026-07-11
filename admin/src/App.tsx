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
import Register from '@/pages/Register';
import ForgotPin from '@/pages/ForgotPin';
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
import Conversations from '@/pages/Conversations';
import Chats from '@/pages/Chats';
import ChatThread from '@/pages/ChatThread';
import WhatsAppSetup from '@/pages/WhatsAppSetup';
import Leads from '@/pages/Leads';
import LeadCredits from '@/pages/LeadCredits';
import LeadDetail from '@/pages/LeadDetail';
import LeadMessages from '@/pages/LeadMessages';
import SimulationSettings from '@/pages/SimulationSettings';
import PublicBooking from '@/pages/PublicBooking';

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
        <Route path="/register" element={<Register />} />
        <Route path="/forgot-pin" element={<ForgotPin />} />
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
          <Route path="/services/new" element={<ServiceEdit />} />
          <Route path="/services/:id/edit" element={<ServiceEdit />} />
          <Route path="/categories" element={<Categories />} />
          <Route path="/categories/new" element={<CategoryEdit />} />
          <Route path="/categories/:id/edit" element={<CategoryEdit />} />
          <Route path="/staff" element={<Staff />} />
          <Route path="/staff/:id/availability" element={<StaffAvailability />} />
          <Route path="/bookings/recurring" element={<RecurringBooking />} />
          <Route element={<ModuleGuard module="bookings" />}>
            <Route path="/customers" element={<Customers />} />
            <Route path="/customers/:id" element={<CustomerDetail />} />
          </Route>
          <Route path="/reviews" element={<Reviews />} />
          <Route path="/insights" element={<Insights />} />
          <Route path="/settings/notifications" element={<BookingNotifications />} />
          <Route element={<ModuleGuard module="leads" />}>
            <Route path="/leads" element={<Leads />} />
            <Route path="/leads/credits" element={<LeadCredits />} />
            <Route path="/leads/messages" element={<LeadMessages />} />
            <Route path="/leads/:id" element={<LeadDetail />} />
          </Route>
          <Route path="/settings/access" element={<AccessControl />} />
          <Route path="/settings/simulation" element={<SimulationSettings />} />
          <Route path="/working-hours" element={<WorkingHours />} />
          <Route path="/category-setup" element={<CategorySetup />} />
          <Route path="/assistant" element={<Assistant />} />
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
            <Route path="/" element={<VoiceAssistant />} />
            <Route path="/ask" element={<VoiceAssistant />} />
            <Route path="/ask/:conversationId" element={<VoiceAssistant />} />
            {/* Full-page list of the shop's Ask conversations (sits below Home in the nav). */}
            <Route path="/conversations" element={<Conversations />} />
            <Route path="/ai-summary" element={<AiSummary />} />
            <Route path="/bookings" element={<Bookings />} />
            <Route path="/chats" element={<Chats />} />
            <Route path="/reminders" element={<Reminders />} />
            <Route path="/services" element={<Services />} />
            <Route path="/settings" element={<Settings />} />
            <Route path="/profile" element={<Profile />} />
          </Route>
          </Route>
          </Route>
        </Route>
      </Routes>
      </SubscriptionProvider>
    </ShopProvider>
  );
}
