import { Routes, Route } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { ParticleField } from '@/components/ParticleField';
import { MobileLayout } from '@/layout/MobileLayout';
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
import MasterShops from '@/pages/MasterShops';
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
      <ParticleField />
      <Routes>
        {/* Public / full-screen */}
        <Route path="/web" element={<Web />} />
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/forgot-pin" element={<ForgotPin />} />
        <Route path="/scan/:token" element={<ScanApprove />} />

        {/* Authenticated full-screen */}
        <Route element={<RequireShop />}>
          <Route path="/booking/:id" element={<BookingAction />} />
          <Route path="/services/new" element={<ServiceEdit />} />
          <Route path="/services/:id/edit" element={<ServiceEdit />} />
          <Route path="/categories" element={<Categories />} />
          <Route path="/categories/new" element={<CategoryEdit />} />
          <Route path="/categories/:id/edit" element={<CategoryEdit />} />
          <Route path="/staff" element={<Staff />} />
          <Route path="/working-hours" element={<WorkingHours />} />
          <Route path="/category-setup" element={<CategorySetup />} />
          <Route path="/assistant" element={<Assistant />} />
          <Route path="/ask" element={<VoiceAssistant />} />
          <Route path="/master" element={<MasterShops />} />
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
      </Routes>
    </ShopProvider>
  );
}
