/**
 * Pure form-validation logic, kept dependency-free and screen-free so it can
 * be unit tested directly (see src/utils/__tests__/validation.test.ts)
 * without pulling in React Native rendering.
 */

const DATE_ONLY_RE = /^\d{4}-\d{2}-\d{2}$/;

export interface ExpenditureFormInput {
    categoryUuid: string | null;
    amountText: string;
    /** Required on mobile — deliberately stricter than the web form
     * (StoreExpenditureRequest.php has `description` as nullable). A field
     * agent's description is often the only paper trail for that expense
     * later, per mobile-app-react-native.md §4 / the Resources module spec. */
    description: string;
    dateText: string;
}

export interface ExpenditureFormErrors {
    category?: string;
    amount?: string;
    description?: string;
    date?: string;
}

export type ExpenditureFormResult =
    | { valid: true; errors: Record<string, never>; amount: number }
    | { valid: false; errors: ExpenditureFormErrors };

export interface ComplaintFormInput {
    category: 'operational' | 'customer' | null;
    title: string;
    /** Web's own Create.tsx presents this as a collapsed "Add more detail
     * (optional)" disclosure, but StoreComplaintRequest.php actually
     * validates it as `required` server-side — a real web UX papercut
     * (collapsed-and-labeled-optional, but a submit with it empty gets
     * bounced back with a 422). Mobile deliberately does NOT reproduce
     * that mismatch: validating it as required here, same "stricter than
     * web to protect the offline confirmation's trustworthiness" precedent
     * as validateExpenditureForm's own description field above — a
     * complaint that shows the amber "Saved · will sync" badge and then
     * permanently fails to sync with a validation error the agent never
     * sees again is exactly the kind of silent trust-eroding failure
     * mobile-app-react-native.md section 5 warns about. */
    description: string;
    customerUuid: string | null;
}

export interface ComplaintFormErrors {
    category?: string;
    title?: string;
    description?: string;
    customer?: string;
}

export type ComplaintFormResult =
    | { valid: true; errors: Record<string, never> }
    | { valid: false; errors: ComplaintFormErrors };

export function validateComplaintForm(input: ComplaintFormInput): ComplaintFormResult {
    const errors: ComplaintFormErrors = {};

    if (!input.category) {
        errors.category = 'Choose a category.';
    }

    if (!input.title.trim()) {
        errors.title = 'Enter a short title.';
    }

    if (!input.description.trim()) {
        errors.description = 'Add a short description — this is what whoever picks it up will see first.';
    }

    if (input.category === 'customer' && !input.customerUuid) {
        errors.customer = 'Choose the customer this complaint is about.';
    }

    if (Object.keys(errors).length > 0) {
        return { valid: false, errors };
    }

    return { valid: true, errors: {} };
}

// --- Profile / password self-service — mobile-app-react-native.md §11 addendum ---

const SIMPLE_EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export interface ProfileFormInput {
    name: string;
    username: string;
    email: string;
}

export interface ProfileFormErrors {
    name?: string;
    username?: string;
    email?: string;
}

export type ProfileFormResult =
    | { valid: true; errors: Record<string, never> }
    | { valid: false; errors: ProfileFormErrors };

/** Client-side pass only — deliberately does NOT duplicate the server's
 * uniqueness check (that requires a round-trip; see AuthController::
 * updateProfile() / UpdateProfileRequest). This just catches the "obviously
 * incomplete/malformed" cases before spending a network call, matching
 * validateComplaintForm/validateExpenditureForm's own scope. */
export function validateProfileForm(input: ProfileFormInput): ProfileFormResult {
    const errors: ProfileFormErrors = {};

    if (!input.name.trim()) {
        errors.name = 'Enter your name.';
    }

    if (!input.username.trim()) {
        errors.username = 'Enter a username.';
    }

    if (!input.email.trim()) {
        errors.email = 'Enter an email address.';
    } else if (!SIMPLE_EMAIL_RE.test(input.email.trim())) {
        errors.email = 'Enter a valid email address.';
    }

    if (Object.keys(errors).length > 0) {
        return { valid: false, errors };
    }

    return { valid: true, errors: {} };
}

export interface PasswordFormInput {
    currentPassword: string;
    newPassword: string;
    confirmPassword: string;
}

export interface PasswordFormErrors {
    currentPassword?: string;
    newPassword?: string;
    confirmPassword?: string;
}

export type PasswordFormResult =
    | { valid: true; errors: Record<string, never> }
    | { valid: false; errors: PasswordFormErrors };

/** Mirrors App\Http\Requests\UpdatePasswordRequest's rules exactly (min 8,
 * at least one letter and one number via Laravel's Password rule object) so
 * a weak password is caught before the round-trip, not just after a 422. */
export function validatePasswordForm(input: PasswordFormInput): PasswordFormResult {
    const errors: PasswordFormErrors = {};

    if (!input.currentPassword) {
        errors.currentPassword = 'Enter your current password.';
    }

    if (!input.newPassword) {
        errors.newPassword = 'Enter a new password.';
    } else if (input.newPassword.length < 8) {
        errors.newPassword = 'Must be at least 8 characters.';
    } else if (!/[a-zA-Z]/.test(input.newPassword) || !/[0-9]/.test(input.newPassword)) {
        errors.newPassword = 'Must include at least one letter and one number.';
    }

    if (input.confirmPassword !== input.newPassword) {
        errors.confirmPassword = 'Passwords do not match.';
    }

    if (Object.keys(errors).length > 0) {
        return { valid: false, errors };
    }

    return { valid: true, errors: {} };
}

export function validateExpenditureForm(input: ExpenditureFormInput): ExpenditureFormResult {
    const errors: ExpenditureFormErrors = {};

    if (!input.categoryUuid) {
        errors.category = 'Choose a category.';
    }

    const trimmedAmount = input.amountText.trim();
    const amount = Number(trimmedAmount);

    if (!trimmedAmount || !Number.isFinite(amount) || amount <= 0) {
        errors.amount = 'Enter an amount greater than 0.';
    }

    if (!input.description.trim()) {
        errors.description = 'Add a short description — this is the paper trail for this expense.';
    }

    if (!DATE_ONLY_RE.test(input.dateText) || Number.isNaN(new Date(input.dateText).getTime())) {
        errors.date = 'Enter a valid date.';
    }

    if (Object.keys(errors).length > 0) {
        return { valid: false, errors };
    }

    return { valid: true, errors: {}, amount };
}
