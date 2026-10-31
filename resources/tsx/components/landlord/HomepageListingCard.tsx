import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { IconWorld } from '@tabler/icons-react';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { LandlordTenant } from '@/types';

/**
 * The tenant's place in the public home page's "Cable companies running on
 * CNCMS" strip (App\Http\Controllers\Landlord\TenantController::
 * updateHomepageListing). Shown only while the tenant is approved and active.
 */
export function HomepageListingCard({ tenant }: { tenant: LandlordTenant }) {
    const { data, setData, post, processing, errors, progress } = useForm<{
        _method: 'patch';
        show_on_homepage: boolean;
        homepage_caption: string;
        homepage_logo: File | null;
        remove_homepage_logo: boolean;
    }>({
        _method: 'patch',
        show_on_homepage: tenant.show_on_homepage,
        homepage_caption: tenant.homepage_caption ?? '',
        homepage_logo: null,
        remove_homepage_logo: false,
    });

    const visible = tenant.show_on_homepage && tenant.is_active && tenant.registration_status === 'approved';

    function submit(event: FormEvent) {
        event.preventDefault();
        post(`/landlord/tenants/${tenant.id}`, { preserveScroll: true, forceFormData: true });
    }

    return (
        <Card className="animate-fade-up mt-6 max-w-md" style={{ animationDelay: '170ms' }}>
            <CardHeader>
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <IconWorld size={18} stroke={1.75} className="text-indigo-600" />
                        <p className="font-medium text-slate-900">Home page listing</p>
                    </div>
                    <Badge tone={visible ? 'green' : 'slate'}>{visible ? 'On cncms.tech' : 'Not shown'}</Badge>
                </div>
            </CardHeader>
            <CardBody>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <label className="flex items-start gap-2.5 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={data.show_on_homepage}
                            onChange={(event) => setData('show_on_homepage', event.target.checked)}
                            className="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                        />
                        <span>
                            Show among "Cable companies running on CNCMS"
                            <span className="block text-xs text-slate-400">Only while the workspace is approved and active.</span>
                        </span>
                    </label>

                    <TextInput
                        id="homepage_caption"
                        label="Line under the name"
                        placeholder="e.g. Multimedia Digital Cable Network · Kumba"
                        value={data.homepage_caption}
                        onChange={(event) => setData('homepage_caption', event.target.value)}
                        error={errors.homepage_caption}
                        maxLength={80}
                    />

                    <div className="flex items-center gap-4">
                        <span className="grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-lg bg-slate-900 text-xs font-bold text-white">
                            {tenant.homepage_logo_url && !data.remove_homepage_logo ? (
                                <img src={tenant.homepage_logo_url} alt="" className="h-full w-full bg-white object-contain" />
                            ) : (
                                tenant.name.slice(0, 4).toUpperCase()
                            )}
                        </span>
                        <div className="flex min-w-0 flex-col gap-1.5">
                            <label htmlFor="homepage_logo" className="text-sm font-medium text-slate-700">
                                Logo <span className="font-normal text-slate-400">(PNG, JPG or WebP, up to 1 MB)</span>
                            </label>
                            <input
                                id="homepage_logo"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(event) => setData('homepage_logo', event.target.files?.[0] ?? null)}
                                className="text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                            />
                            {errors.homepage_logo && <p className="text-xs text-red-600">{errors.homepage_logo}</p>}
                            {tenant.homepage_logo_url && (
                                <label className="flex items-center gap-2 text-xs text-slate-500">
                                    <input
                                        type="checkbox"
                                        checked={data.remove_homepage_logo}
                                        onChange={(event) => setData('remove_homepage_logo', event.target.checked)}
                                        className="rounded border-slate-300"
                                    />
                                    Remove the logo (show initials)
                                </label>
                            )}
                        </div>
                    </div>

                    <div className="border-t border-slate-100 pt-4">
                        <Button type="submit" disabled={processing} className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto">
                            {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                            {processing && progress ? `Uploading ${progress.percentage}%` : 'Save listing'}
                        </Button>
                    </div>
                </form>
            </CardBody>
        </Card>
    );
}
