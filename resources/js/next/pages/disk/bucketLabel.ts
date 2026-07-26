// Localize a resource-bucket key (created_at bucket in the "Zasoby" tree) for display.
// The backend fixes the key format by the type's granularity: YYYY (year), YYYY-MM
// (month), YYYY-MM-DD (day). Anything that doesn't parse falls back to the raw key.

type Granularity = 'year' | 'month' | 'day' | undefined;

export function bucketLabel(key: string, granularity: Granularity, locale: string): string {
  const parts = key.split('-').map((p) => Number.parseInt(p, 10));
  const [year, month, day] = parts;

  if (granularity === 'year' || parts.length === 1) {
    return Number.isFinite(year) ? String(year) : key;
  }

  if (!Number.isFinite(year) || !Number.isFinite(month)) return key;

  if (granularity === 'day' && Number.isFinite(day)) {
    return new Intl.DateTimeFormat(locale, { dateStyle: 'long' }).format(new Date(year, month - 1, day));
  }

  // Month (the default granularity for every registered type today).
  return new Intl.DateTimeFormat(locale, { year: 'numeric', month: 'long' }).format(
    new Date(year, month - 1, 1),
  );
}
