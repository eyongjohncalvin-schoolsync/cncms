export function formatCurrency(value: string | number): string {
    const amount = typeof value === 'string' ? parseFloat(value) : value;

    if (Number.isNaN(amount)) {
        return '0 FCFA';
    }

    return `${new Intl.NumberFormat('en-US').format(amount)} FCFA`;
}
