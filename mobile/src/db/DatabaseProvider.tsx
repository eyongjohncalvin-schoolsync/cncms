import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { runMigrations } from './database';

interface DatabaseContextValue {
    ready: boolean;
    error: string | null;
}

const DatabaseContext = createContext<DatabaseContextValue>({ ready: false, error: null });

/**
 * Runs create-tables-if-not-exist migrations once on app launch, before
 * rendering anything that queries SQLite. See src/db/database.ts for the
 * actual schema and mobile-app-react-native.md §2/§4.
 */
export function DatabaseProvider({ children }: { children: ReactNode }) {
    const [ready, setReady] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        void (async () => {
            try {
                await runMigrations();
                setReady(true);
            } catch (e) {
                setError(e instanceof Error ? e.message : 'Failed to initialize local database.');
            }
        })();
    }, []);

    return <DatabaseContext.Provider value={{ ready, error }}>{children}</DatabaseContext.Provider>;
}

export function useDatabaseStatus(): DatabaseContextValue {
    return useContext(DatabaseContext);
}
