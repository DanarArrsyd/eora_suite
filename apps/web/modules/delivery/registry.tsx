'use client';

import { AllocationPickScreen } from './allocation-pick';
import { ShipmentsScreen } from './shipments';

/** Delivery → Shipping on the live API (doc 33 §1.7): allocation and pick. */
export function DeliveryScreen({ item }: { item: string }) {
  if (item === 'allocation-pick') return <AllocationPickScreen />;
  if (item === 'shipments') return <ShipmentsScreen />;
  return null;
}
