'use client';

import * as React from 'react';
import { ControlTowerOverview } from './screens/overview';
import { DailyDeliveryScreen, DeliveryRiskScreen, DemandFulfillmentScreen } from './screens/demand';
import { ConversionScreen, MaterialAtProductionScreen, RmAvailabilityScreen } from './screens/material';
import {
  DailyFgScreen,
  DailyProductionScreen,
  FgAvailabilityScreen,
  MaterialVarianceScreen,
  PendingQualityScreen,
  WoProgressScreen,
} from './screens/operations';

/** Phase 3 screens implemented for navigation items `ppic/<item>` (doc 19). Other items keep the honest "planned" page. */
export const PPIC_SCREENS: Record<string, React.ComponentType> = {
  'control-tower': ControlTowerOverview,
  'demand-fulfillment': DemandFulfillmentScreen,
  'daily-delivery': DailyDeliveryScreen,
  shortage: DeliveryRiskScreen,
  'rm-availability': RmAvailabilityScreen,
  conversion: ConversionScreen,
  'material-at-production': MaterialAtProductionScreen,
  'wo-progress': WoProgressScreen,
  'daily-results': DailyProductionScreen,
  'pending-quality': PendingQualityScreen,
  'daily-fg': DailyFgScreen,
  'fg-availability': FgAvailabilityScreen,
  'material-variance': MaterialVarianceScreen,
};

export function PpicScreen({ item }: { item: string }) {
  const Screen = PPIC_SCREENS[item];
  return Screen ? <Screen /> : null;
}
