import app from 'flarum/forum/app';

export type Row = {
  id: number;
  username: string;
  displayName: string;
  slug: string;
  avatarUrl: string | null;
  color: string | null;
  level: number;
  prestige: number;
  xp: number;
  lifetime: number;
  position?: number;
  gained?: number;
  week?: number;
};

/** An avatar for a plain row: core's Avatar wants a user model. */
export const avatarUser = (row: Row) =>
  ({ avatarUrl: () => row.avatarUrl, displayName: () => row.displayName, username: () => row.username, color: () => row.color || '' }) as any;

export const memberUrl = (row: Row) => app.route('user.renown', { username: row.slug });
