import { IconDownload, IconSignature } from '@tabler/icons-react';
import { Card, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';

export interface LandlordContractSignature {
    id: number;
    version: number;
    signer_name: string;
    signer_title: string | null;
    signed_at: string;
    ip_address: string | null;
}

/**
 * The company's online signatures of the service contract (App\Services\
 * ContractService), newest first, each with its stored PDF.
 */
export function ContractCard({ contracts, currentVersion }: { contracts: LandlordContractSignature[]; currentVersion: number | null }) {
    const signedCurrent = currentVersion !== null && contracts.some((contract) => contract.version === currentVersion);

    return (
        <Card className="animate-fade-up mt-6 max-w-md" style={{ animationDelay: '125ms' }}>
            <CardHeader>
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <IconSignature size={18} stroke={1.75} className="text-indigo-600" />
                        <p className="font-medium text-slate-900">Service contract</p>
                    </div>
                    {currentVersion === null ? (
                        <Badge tone="slate">None published</Badge>
                    ) : (
                        <Badge tone={signedCurrent ? 'green' : 'yellow'}>{signedCurrent ? `Signed v${currentVersion}` : `v${currentVersion} not signed`}</Badge>
                    )}
                </div>
            </CardHeader>
            {contracts.length === 0 ? (
                <p className="px-4 py-3 text-sm text-slate-500">
                    {currentVersion === null ? 'Publish the contract in Legal documents first.' : 'Not signed yet. Their admins see a "please sign" bar in the app.'}
                </p>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {contracts.map((contract) => (
                        <li key={contract.id} className="flex items-center justify-between gap-3 px-4 py-3">
                            <div className="min-w-0 text-sm">
                                <p className="font-medium text-slate-900">Version {contract.version}</p>
                                <p className="truncate text-xs text-slate-500">
                                    {contract.signer_name}
                                    {contract.signer_title && `, ${contract.signer_title}`} ·{' '}
                                    {new Date(contract.signed_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}
                                    {contract.ip_address && ` · ${contract.ip_address}`}
                                </p>
                            </div>
                            <a
                                href={`/landlord/contracts/${contract.id}/pdf`}
                                className="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-blue-700 ring-1 ring-blue-200 hover:bg-blue-50"
                            >
                                <IconDownload size={16} stroke={1.75} />
                                PDF
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
