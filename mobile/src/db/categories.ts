import { getDatabase } from './database';
import type { LocalExpenseCategory } from '../types/db';
import type { ExpenseCategoryApi } from '../types/api';

export async function upsertExpenseCategories(categories: ExpenseCategoryApi[]): Promise<void> {
    if (categories.length === 0) {
        return;
    }

    const db = await getDatabase();

    await db.withTransactionAsync(async () => {
        for (const category of categories) {
            await db.runAsync(
                `INSERT INTO expense_categories (uuid, name, icon, is_active, sort_order)
                 VALUES ($uuid, $name, $icon, $is_active, $sort_order)
                 ON CONFLICT(uuid) DO UPDATE SET
                    name = excluded.name,
                    icon = excluded.icon,
                    is_active = excluded.is_active,
                    sort_order = excluded.sort_order`,
                {
                    $uuid: category.uuid,
                    $name: category.name,
                    $icon: category.icon,
                    $is_active: category.is_active ? 1 : 0,
                    $sort_order: category.sort_order,
                },
            );
        }
    });
}

export async function getExpenseCategories(): Promise<LocalExpenseCategory[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalExpenseCategory>(
        'SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY sort_order ASC',
    );
}
