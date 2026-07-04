import { Routes, Route } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { SubscriptionProvider } from '@/context/SubscriptionContext';
import RequireSubscription from '@/components/RequireSubscription';
import Subscribe from '@/pages/Subscribe';
import { ParticleField } from '@/components/ParticleField';
import { MobileLayout } from '@/layout/MobileLayout';
import { AppShell } from '@/layout/AppShell';
import { RequireShop } from '@/layout/RequireShop';
import Login from '@/pages/Login';
import Register from '@/pages/Register';
import ForgotPin from '@/pages/ForgotPin';
import Web from '@/pages/Web';
import ScanApprove from '@/pages/ScanApprove';
import Dashboard from '@/pages/Dashboard';
import Bookings from '@/pages/Bookings';
import BookingAction from '@/pages/BookingAction';
import Reminders from '@/pages/Reminders';
import Services from '@/pages/Services';
import ServiceEdit from '@/pages/ServiceEdit';
import Categories from '@/pages/Categories';
import CategoryEdit from '@/pages/CategoryEdit';
import Staff from '@/pages/Staff';
import WorkingHours from '@/pages/WorkingHours';
import Profile from '@/pages/Profile';
import Settings from '@/pages/Settings';
import AccessControl from '@/pages/AccessControl';
import MasterShops from '@/pages/MasterShops';
import MasterShopCreate from '@/pages/MasterShopCreate';
import MasterShopDetail from '@/pages/MasterShopDetail';
import CategorySetup from '@/pages/CategorySetup';
import Assistant from '@/pages/Assistant';
import VoiceAssistant from '@/pages/VoiceAssistant';
import Chats from '@/pages/Chats';
import ChatThread from '@/pages/ChatThread';
import WhatsAppSetup from '@/pages/WhatsAppSetup';

export default function App() {
  return (
    <ShopProvider>
      <SubscriptionProvider>
      <ParticleField />
      <Routes>
        {/* Public / full-screen */}
        <Route path="/web" element={<Web />} />
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/forgot-pin" element={<ForgotPin />} />
        <Route path="/scan/:token" element={<ScanApprove />} />

        {/* Authenticated — wrapped in the desktop AppShell (sidebar at ≥1024px) */}
        <Route element={<RequireShop />}>
          {/* Paywall: logged-in but NOT subscription-gated, so lapsed shops can pay. */}
          <Route path="/subscribe" element={<Subscribe />} />
          <Route element={<AppShell />}>
          <Route element={<RequireSubscription />}>
          <Route path="/booking/:id" element={<BookingAction />} />
          <Route path="/services/new" element={<ServiceEdit />} />
          <Route path="/services/:id/edit" element={<ServiceEdit />} />
          <Route path="/categories" element={<Categories />} />
          <Route path="/categories/new" element={<CategoryEdit />} />
          <Route path="/categories/:id/edit" element={<CategoryEdit />} />
          <Route path="/staff" element={<Staff />} />
          <Route path="/settings/access" element={<AccessControl />} />
          <Route path="/working-hours" element={<WorkingHours />} />
          <Route path="/category-setup" element={<CategorySetup />} />
          <Route path="/assistant" element={<Assistant />} />
          <Route path="/ask" element={<VoiceAssistant />} />
          <Route path="/master" element={<MasterShops />} />
          <Route path="/master/new" element={<MasterShopCreate />} />
          <Route path="/master/:id" element={<MasterShopDetail />} />
          <Route path="/chats/setup" element={<WhatsAppSetup />} />
          <Route path="/chats/:id" element={<ChatThread />} />

          {/* Authenticated tabbed */}
          <Route element={<MobileLayout />}>
            <Route path="/" element={<Dashboard />} />
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
