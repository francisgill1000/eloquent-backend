import api from './api';

export type Schedule = { id?: number; day_of_week: number; start_time: string; end_time: string };
export type TimeOff = {
  id: number;
  date: string;
  start_time: string | null;
  end_time: string | null;
  reason: string | null;
};

export async function getSchedule(shopId: number, staffId: number): Promise<Schedule[]> {
  const { data } = await api.get(`/shops/${shopId}/staff/${staffId}/schedule`);
  return Array.isArray(data?.data) ? data.data : [];
}

export async function setSchedule(shopId: number, staffId: number, schedule: Schedule[]): Promise<Schedule[]> {
  const { data } = await api.put(`/shops/${shopId}/staff/${staffId}/schedule`, { schedule });
  return Array.isArray(data?.data) ? data.data : [];
}

export async function getTimeOff(shopId: number, staffId: number): Promise<TimeOff[]> {
  const { data } = await api.get(`/shops/${shopId}/staff/${staffId}/time-off`);
  return Array.isArray(data?.data) ? data.data : [];
}

export async function addTimeOff(
  shopId: number,
  staffId: number,
  payload: { date: string; start_time?: string | null; end_time?: string | null; reason?: string | null },
): Promise<TimeOff> {
  const { data } = await api.post(`/shops/${shopId}/staff/${staffId}/time-off`, payload);
  return data?.data ?? data;
}

export async function deleteTimeOff(shopId: number, staffId: number, timeOffId: number): Promise<void> {
  await api.delete(`/shops/${shopId}/staff/${staffId}/time-off/${timeOffId}`);
}
