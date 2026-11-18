/**
 * All amounts arrive from the server as integer nanodollars (1 USD = 1e9).
 * Formatting rounds for display only; the exact value is always available via exactUsd().
 */
export const NANOS_PER_USD = 1_000_000_000;

export function formatUsd(nanos: number, decimals = 4): string {
    const unit = 10 ** (9 - decimals);
    const rounded = Math.round(Math.abs(nanos) / unit);
    const whole = Math.floor(rounded / 10 ** decimals);
    const fraction = String(rounded % 10 ** decimals).padStart(decimals, '0');

    return `${nanos < 0 ? '-' : ''}$${whole.toLocaleString('en-US')}${decimals > 0 ? '.' + fraction : ''}`;
}

/** The exact nine-decimal amount, e.g. "$0.381887500", for tooltips and reconciliation. */
export function exactUsd(nanos: number): string {
    const abs = Math.abs(nanos);

    return `${nanos < 0 ? '-' : ''}$${Math.floor(abs / NANOS_PER_USD)}.${String(abs % NANOS_PER_USD).padStart(9, '0')}`;
}

/** "5.00" (USD) → nanodollars, parsed exactly from the string. */
export function parseUsd(usd: string): number {
    const [whole, fraction = ''] = usd.split('.');

    return Number(whole) * NANOS_PER_USD + Number(fraction.padEnd(9, '0').slice(0, 9));
}
