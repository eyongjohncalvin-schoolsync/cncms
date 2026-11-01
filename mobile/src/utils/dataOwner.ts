/**
 * What to do with the phone's offline data when someone signs in
 * (AuthContext, src/db/localData.ts). The data is stamped
 * `<company>:<person>`; it may only ever be shown to, or sent under, that
 * same account.
 *
 *  - `keep`   — it is this account's (or there is nothing on the phone);
 *  - `wipe`   — it is someone else's, all of it already sent: delete it,
 *               then download this account's;
 *  - `refuse` — it is someone else's and some is not yet sent: neither
 *               show nor send it; refuse the sign-in until that person
 *               sends it or it is deliberately deleted.
 */
export type DataOwnerDecision = 'keep' | 'wipe' | 'refuse';

export function dataOwnerKey(workspace: string | undefined, userUuid: string): string {
    return `${workspace ?? ''}:${userUuid}`;
}

export function decideOnSignIn(storedOwner: string | null, signingIn: string, phoneHasData: boolean, unsent: number): DataOwnerDecision {
    if (storedOwner === signingIn) {
        return 'keep';
    }

    // Never stamped (an install from before this check, or a wiped phone):
    // anything on it can't be proven to be this account's.
    if (storedOwner === null && !phoneHasData && unsent === 0) {
        return 'keep';
    }

    return unsent > 0 ? 'refuse' : 'wipe';
}
