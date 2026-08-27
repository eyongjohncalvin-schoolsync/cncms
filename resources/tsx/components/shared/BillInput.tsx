import { useState } from 'react';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';

// Covers SWECOM's actual rate tiers (2,000-12,500 FCFA/month per
// cncms-context's database reference) plus headroom up to the 30,000
// ceiling requested for future/higher-tier customers. "Other" always stays
// available for anything outside this list — the picker is a shortcut for
// the common case, not a restriction on what a bill can be.
const BILL_PRESETS = ['2000', '2500', '3000', '3500', '4000', '5000', '6000', '7500', '10000', '12500', '15000', '20000', '25000', '30000'];

interface BillInputProps {
    defaultValue?: string;
    error?: string;
}

/**
 * Renders as a preset dropdown (submits via a hidden `bill` input) when the
 * starting value matches one of BILL_PRESETS, or as a free-number input
 * otherwise — so an existing customer on a non-standard rate still shows
 * their real amount instead of silently snapping to the nearest preset.
 * Used inside Inertia's uncontrolled <Form> component (Customers/Create.tsx,
 * Customers/Edit.tsx), so the submitted field is always a real DOM node
 * named `bill`, not React state read out-of-band.
 */
export function BillInput({ defaultValue = '2500', error }: BillInputProps) {
    const matchedPreset = BILL_PRESETS.find((preset) => Number(preset) === Number(defaultValue));

    const [mode, setMode] = useState<'preset' | 'custom'>(matchedPreset ? 'preset' : 'custom');
    const [value, setValue] = useState(matchedPreset ?? defaultValue);

    if (mode === 'custom') {
        return (
            <div className="flex flex-col gap-1">
                <TextInput
                    id="bill"
                    name="bill"
                    type="number"
                    step="0.01"
                    min="0"
                    label="Monthly Bill (FCFA)"
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    error={error}
                    required
                />
                <button
                    type="button"
                    onClick={() => {
                        setMode('preset');
                        setValue('2500');
                    }}
                    className="self-start text-xs font-medium text-blue-600 hover:text-blue-700"
                >
                    Choose from presets instead
                </button>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-1">
            <SelectInput
                id="bill"
                label="Monthly Bill (FCFA)"
                value={value}
                onChange={(e) => (e.target.value === 'custom' ? setMode('custom') : setValue(e.target.value))}
                error={error}
                required
            >
                {BILL_PRESETS.map((preset) => (
                    <option key={preset} value={preset}>
                        {Number(preset).toLocaleString('en-US')} FCFA
                    </option>
                ))}
                <option value="custom">Other (type an amount)…</option>
            </SelectInput>
            <input type="hidden" name="bill" value={value} />
        </div>
    );
}
