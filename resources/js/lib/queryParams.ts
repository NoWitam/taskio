export function asStringArray(v: unknown): string[] {
  if (Array.isArray(v)) return v.map((x) => String(x)).filter(Boolean);
  if (typeof v === 'string') return v.trim() ? [v] : [];
  return [];
}

export function asString(v: unknown): string {
  if (Array.isArray(v)) return v.length ? String(v[0] ?? '') : '';
  if (v === undefined || v === null) return '';
  return String(v);
}

export function asBool(v: unknown): boolean {
  const s = asString(v).trim().toLowerCase();
  return s === '1' || s === 'true' || s === 'yes' || s === 'on';
}
