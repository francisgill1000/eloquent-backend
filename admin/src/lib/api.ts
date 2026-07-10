import axios from 'axios';
import { storage } from './storage';
import { getDeviceId } from './deviceId';

const BASE_URL = import.meta.env.VITE_API_URL ?? 'https://api.eloquentservice.com/api';
export const API_BASE = BASE_URL;

const api = axios.create({
  baseURL: BASE_URL,
  headers: { 'Content-Type': 'application/json' },
});

api.interceptors.request.use((config) => {
  config.headers = config.headers ?? {};
  // Respect an explicitly-set device id (the public booking flow sends its own
  // booking-session id); otherwise default to the app-wide device id.
  if (!config.headers['X-Device-Id']) config.headers['X-Device-Id'] = getDeviceId();

  const token = storage.get('shop_token');
  if (token && !config.headers.Authorization) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// A 402 means the shop's subscription has lapsed — send them to the paywall.
api.interceptors.response.use(
  (r) => r,
  (error) => {
    if (error?.response?.status === 402 && !window.location.pathname.startsWith('/subscribe')) {
      window.location.assign('/subscribe');
    }
    return Promise.reject(error);
  },
);

export default api;
