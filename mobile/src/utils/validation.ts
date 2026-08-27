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
