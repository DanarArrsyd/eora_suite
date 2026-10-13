import * as React from 'react';
import { z } from 'zod';
import { EoraForm, FormDateField, FormSelectField, FormTextareaField, useFormContext, useWatch } from '@eora/features/patterns';
import { Button, todayIn } from '@eora/ui';
import { useAccessActions, type Member, type Role } from './api';
import { formFailure } from './errors';
import { defaultScope, endOfDayIn, scopeChoices, type PlantRef } from './model';

const schema = z
  .object({
    membershipId: z.string().min(1, 'Choose a member'),
    roleId: z.string().min(1, 'Choose a role'),
    scopeType: z.enum(['TENANT', 'PLANT']),
    plantId: z.string(),
    validUntil: z.string(),
    reason: z.string().trim().min(3, 'Enter a reason of at least 3 characters').max(500, 'Keep the reason under 500 characters'),
  })
  .superRefine((v, ctx) => {
    if (v.scopeType === 'PLANT' && !v.plantId) ctx.addIssue({ code: 'custom', path: ['plantId'], message: 'Choose a plant' });
  });

type Props = {
  members: readonly Member[];
  roles: readonly Role[];
  plants: readonly PlantRef[];
  /** Tenant IANA zone (D-044): the end date is the end of that day there. */
  timeZone: string;
  /** Fixed member (from the member drawer) or fixed role (from the role drawer). */
  memberId?: string;
  roleId?: string;
  onDone: (message: string) => void;
  onCancel: () => void;
};

function ScopeFields({ roles, plants }: { roles: readonly Role[]; plants: readonly PlantRef[] }) {
  const { setValue } = useFormContext();
  const roleId = useWatch({ name: 'roleId' }) as string;
  const scope = useWatch({ name: 'scopeType' }) as string;
  const role = roles.find((r) => r.id === roleId);
  const choices = scopeChoices(role, plants);

  // a new role suggests its template scope; the administrator can still change it
  const lastRole = React.useRef(roleId);
  React.useEffect(() => {
    if (lastRole.current !== roleId) {
      lastRole.current = roleId;
      setValue('scopeType', defaultScope(role, plants));
    }
  }, [roleId, role, plants, setValue]);

  return (
    <>
      <FormSelectField
        name="scopeType"
        label="Scope"
        required
        hint="Where the role applies. Company scope becomes available with company administration."
        options={choices.filter((c) => !c.disabled).map(({ value, label }) => ({ value, label }))}
        placeholder="Choose a scope"
      />
      {scope === 'PLANT' ? (
        <FormSelectField
          name="plantId"
          label="Plant"
          required
          options={plants.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))}
          placeholder="Choose a plant"
        />
      ) : null}
    </>
  );
}

/**
 * Grant a role in a scope (doc 25 §13, D-068). The reason is stored with the assignment; the server checks that the
 * administrator's own scope covers the target scope (SCOPE_FORBIDDEN otherwise) and refuses changes to one's own access.
 */
export function AssignRoleForm({ members, roles, plants, timeZone, memberId, roleId, onDone, onCancel }: Props) {
  const { assign } = useAccessActions();
  const assignable = members.filter((m) => m.status === 'INVITED' || m.status === 'ACTIVE');
  const initialRole = roles.find((r) => r.id === roleId);

  return (
    <EoraForm
      label="Assign role"
      schema={schema}
      defaultValues={{
        membershipId: memberId ?? '',
        roleId: roleId ?? '',
        scopeType: defaultScope(initialRole, plants),
        plantId: '',
        validUntil: '',
        reason: '',
      }}
      submitLabel="Assign role"
      secondary={
        <Button type="button" onClick={onCancel}>
          Cancel
        </Button>
      }
      onSubmit={async (v) => {
        try {
          await assign.mutateAsync({
            membershipId: v.membershipId,
            roleId: v.roleId,
            scopeType: v.scopeType,
            plantId: v.scopeType === 'PLANT' ? v.plantId : null,
            validUntil: v.validUntil ? endOfDayIn(v.validUntil, timeZone) : null,
            reason: v.reason,
          });
          const role = roles.find((r) => r.id === v.roleId);
          const member = members.find((m) => m.membershipId === v.membershipId);
          onDone(`${role?.name ?? 'Role'} assigned to ${member?.displayName ?? 'the member'}`);
          return { ok: true, message: 'Role assigned' };
        } catch (e) {
          return formFailure(e, 'The role could not be assigned. Nothing was changed.');
        }
      }}
    >
      {memberId ? null : (
        <FormSelectField
          name="membershipId"
          label="Member"
          required
          options={assignable.map((m) => ({ value: m.membershipId, label: `${m.displayName} (${m.email})` }))}
          placeholder="Choose a member"
        />
      )}
      {roleId ? null : (
        <FormSelectField
          name="roleId"
          label="Role"
          required
          options={roles.map((r) => ({ value: r.id, label: r.permissions.length ? `${r.name} (${r.code})` : `${r.name} (${r.code}) · no permissions yet` }))}
          placeholder="Choose a role"
        />
      )}
      <ScopeFields roles={roles} plants={plants} />
      <FormDateField
        name="validUntil"
        label="Access ends"
        min={todayIn(timeZone)}
        today={todayIn(timeZone)}
        placeholder="No end date"
        hint={`Optional. The role stops at the end of this day (${timeZone}).`}
      />
      <FormTextareaField name="reason" label="Reason" required rows={3} hint="Kept with the assignment for audit, e.g. the request or ticket." />
    </EoraForm>
  );
}
