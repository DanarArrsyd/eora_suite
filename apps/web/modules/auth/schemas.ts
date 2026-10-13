import { z } from 'zod';

/** Mirrors the server rules (D-B1-07): 12–1024 characters; the server also refuses breached passwords. */
export const newPasswordSchema = z
  .object({
    password: z.string().min(12, 'Use at least 12 characters.').max(1024, 'Use at most 1024 characters.'),
    password_confirmation: z.string().min(1, 'Repeat the password.'),
  })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'The passwords do not match.' });

export const PASSWORD_HINT = 'At least 12 characters. Passwords that appear in known data breaches are refused.';
