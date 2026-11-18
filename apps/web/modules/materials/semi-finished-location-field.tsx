'use client';

import { useQuery } from '@tanstack/react-query';
import { Field, Select } from '@eora/ui';
import { queryAll } from '@/modules/master/source';

export function SemiFinishedLocationField({ plantId, value, onValueChange }: { plantId: string; value: string; onValueChange: (id: string) => void }) {
  const locations = useQuery({
    queryKey: ['master', 'core.locations', 'semi-finished', plantId],
    queryFn: ({ signal }) =>
      queryAll<{ id: string; code: string; name: string }>('core.locations', { plantId, locationType: 'RM_WAREHOUSE', status: 'ACTIVE' }, signal, 'code'),
  });

  return (
    <Field label="Semi-finished destination" required>
      {(control) => (
        <Select
          {...control}
          value={value}
          onValueChange={onValueChange}
          placeholder={locations.isPending ? 'Loading locations…' : locations.isError ? 'Locations could not be loaded' : 'Choose a materials location'}
          options={(locations.data ?? []).map((location) => ({ value: location.id, label: `${location.code} · ${location.name}` }))}
        />
      )}
    </Field>
  );
}
