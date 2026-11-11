/*
 * The climb, the same table as src/Curve.php: reaching level L takes
 * 30·(L−1)^1.75 XP in total, rounded to tens.
 */
export const MAX_LEVEL = 100;
export const RANKS = 20;
export const ETERNAL = 21;

const table: number[] = [];
for (let level = 1; level <= MAX_LEVEL; level++) {
  table[level] = level === 1 ? 0 : Math.round((30 * Math.pow(level - 1, 1.75)) / 10) * 10;
}

export const xpFor = (level: number): number => table[Math.max(1, Math.min(MAX_LEVEL, level))];

export const rankOf = (level: number): number => Math.floor((Math.max(1, level) - 1) / 5) + 1;

export const gradeOf = (level: number): number => ((Math.max(1, level) - 1) % 5) + 1;

/** XP into this level and the size of the step to the next; null at Level 100. */
export function progress(level: number, xp: number): { into: number; step: number; next: number } | null {
  if (level >= MAX_LEVEL) return null;
  const from = xpFor(level);
  const next = xpFor(level + 1);
  return { into: Math.max(0, xp - from), step: next - from, next };
}
