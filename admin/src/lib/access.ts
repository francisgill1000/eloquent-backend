import api from './api';
import type { Role, ShopUser, PermGroup, Me } from '@/types';

export async function fetchMe(): Promise<Me> {
  const { data } = await api.get('/auth/me');
  return { user: data.user ?? null, permissions: Array.isArray(data.permissions) ? data.permissions : [] };
}

export async function listPermissionGroups(): Promise<Record<string, PermGroup>> {
  const { data } = await api.get('/shop/permissions');
  return data.data ?? {};
}

export async function listRoles(): Promise<Role[]> {
  const { data } = await api.get('/shop/roles');
  return Array.isArray(data?.data) ? data.data : [];
}

export async function createRole(p: { name: string; permissions: string[] }): Promise<Role> {
  const { data } = await api.post('/shop/roles', p);
  return data.data ?? data;
}

export async function updateRole(id: number, p: { name: string; permissions: string[] }): Promise<Role> {
  const { data } = await api.put(`/shop/roles/${id}`, p);
  return data.data ?? data;
}

export async function deleteRole(id: number): Promise<void> {
  await api.delete(`/shop/roles/${id}`);
}

export async function listUsers(): Promise<ShopUser[]> {
  const { data } = await api.get('/shop/users');
  return Array.isArray(data?.data) ? data.data : [];
}

export async function createUser(p: {
  name: string;
  email: string;
  password: string;
  role_id: number | null;
  is_active: boolean;
}): Promise<ShopUser> {
  const { data } = await api.post('/shop/users', p);
  return data.data ?? data;
}

export async function updateUser(
  id: number,
  p: { name: string; email: string; password?: string; role_id: number | null; is_active: boolean },
): Promise<ShopUser> {
  const { data } = await api.put(`/shop/users/${id}`, p);
  return data.data ?? data;
}

export async function deleteUser(id: number): Promise<void> {
  await api.delete(`/shop/users/${id}`);
}
