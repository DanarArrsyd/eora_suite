import { z } from 'zod';
import { EoraForm, FormTextField } from '@eora/features/patterns';
import { Button, Modal, ModalBody, ModalContent } from '@eora/ui';
import { useAccessActions } from './api';
import { formFailure } from './errors';

const schema = z.object({
  email: z
    .string()
    .trim()
    .min(1, 'Enter an e-mail address.')
    .max(254, 'Use at most 254 characters.')
    .pipe(z.email({ error: 'Enter a valid e-mail address.' })),
});

/**
 * Invite a person by e-mail (doc 25 §2.4). The invitation grants no access by itself: the member appears as Invited and
 * gets roles separately. The one-time link is delivered by the mail worker, never shown here.
 */
export function InviteDialog({ open, onOpenChange, onInvited }: { open: boolean; onOpenChange: (open: boolean) => void; onInvited: (email: string) => void }) {
  const { invite } = useAccessActions();
  return (
    <Modal open={open} onOpenChange={onOpenChange}>
      <ModalContent title="Invite user" description="Send an invitation e-mail to a new member of this workspace." closeLabel="Close">
        <ModalBody>
          <p className="m-0 mb-4 text-ink-2">
            The person receives a one-time link to set a password. Inviting gives no access yet: assign a role after the invitation is sent.
          </p>
          <EoraForm
            label="Invite user"
            schema={schema}
            defaultValues={{ email: '' }}
            submitLabel="Send invitation"
            secondary={
              <Button type="button" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
            }
            onSubmit={async ({ email }) => {
              try {
                await invite.mutateAsync(email);
                onInvited(email);
                return { ok: true, message: 'Invitation sent' };
              } catch (e) {
                return formFailure(e, 'The invitation could not be sent. Nothing was changed.');
              }
            }}
          >
            <FormTextField name="email" label="E-mail" required type="email" autoComplete="off" inputMode="email" />
          </EoraForm>
        </ModalBody>
      </ModalContent>
    </Modal>
  );
}
