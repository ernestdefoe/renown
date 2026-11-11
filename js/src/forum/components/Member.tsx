import app from 'flarum/forum/app';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import Insignia from '../../common/Insignia';
import Emblem from '../../common/Emblem';
import { levelLabel, tierName } from '../../common/names';

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
};

/** An avatar for a plain row: core's Avatar wants a user model. */
export const avatarUser = (row: Row) =>
  ({ avatarUrl: () => row.avatarUrl, displayName: () => row.displayName, username: () => row.username, color: () => row.color || '' }) as any;

export const memberUrl = (row: Row) => app.route('user.renown', { username: row.slug });

export function MemberCell(row: Row) {
  return (
    <Link className="RenownMember" href={memberUrl(row)}>
      <Avatar user={avatarUser(row)} className="RenownMember-avatar" />
      <span className="RenownMember-name">{row.displayName}</span>
    </Link>
  );
}

export function Standing(row: Row) {
  return (
    <span className="RenownStanding">
      <Insignia level={row.level} size={20} decorative />
      {row.prestige > 0 ? <Emblem tier={row.prestige} size={20} decorative /> : null}
      <span className="RenownStanding-text">
        {levelLabel(row.level)}
        {row.prestige > 0 ? <span className="RenownStanding-tier"> · {tierName(row.prestige)}</span> : null}
      </span>
    </span>
  );
}
