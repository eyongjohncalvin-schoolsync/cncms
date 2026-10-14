import { IconMail, IconSend } from '@tabler/icons-react';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import type { PreferredChannel } from '@/types';

const channelOptions: { value: PreferredChannel; label: string }[] = [
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'email', label: 'Email' },
    { value: 'print', label: 'Printed receipt' },
];

/**
 * Email + "send receipts by" — shared by the customer Create and Edit forms.
 * Email receipts go out automatically when Settings → Notifications has
 * auto-send on; WhatsApp ones wait in the "To send" list; printed ones are
 * handed over.
 */
export function ReceiptDeliveryFields({
    email,
    preferredChannel,
    errors,
    onEmailChange,
    onChannelChange,
}: {
    email: string;
    preferredChannel: PreferredChannel;
    errors: { email?: string; preferred_channel?: string };
    onEmailChange: (value: string) => void;
    onChannelChange: (value: PreferredChannel) => void;
}) {
    const needsEmail = preferredChannel === 'email' && email.trim() === '';

    return (
        <>
            <div>
                <TextInput
                    id="email"
                    name="email"
                    type="email"
                    label="Email (optional)"
                    value={email}
                    onChange={(e) => onEmailChange(e.target.value)}
                    error={errors.email}
                    placeholder="name@example.com"
                    className="rounded-xl px-4 py-3"
                />
                <p className="mt-2 flex items-center gap-1 text-xs text-slate-500">
                    <IconMail size={12} />
                    Receipts can be emailed with the PDF attached
                </p>
            </div>
            <div>
                <SelectInput
                    id="preferred_channel"
                    name="preferred_channel"
                    label="Send receipts by"
                    value={preferredChannel}
                    onChange={(e) => onChannelChange(e.target.value as PreferredChannel)}
                    error={errors.preferred_channel}
                    className="rounded-xl px-4 py-3"
                >
                    {channelOptions.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </SelectInput>
                <p className={`mt-2 flex items-center gap-1 text-xs ${needsEmail ? 'text-amber-700' : 'text-slate-500'}`}>
                    <IconSend size={12} />
                    {needsEmail ? 'Add an email address, or receipts will wait in the "To send" list instead' : 'How this customer gets their payment receipts'}
                </p>
            </div>
        </>
    );
}
