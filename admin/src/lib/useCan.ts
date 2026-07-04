import { useShop } from '@/context/ShopContext';

/** Returns the permission-check function: `const can = useCan(); can('staff.manage')`. */
export function useCan() {
  return useShop().can;
}
