import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import { gradeOf, rankOf } from './curve';
import { levelLabel, rankIcon } from './names';

/*
 * The rank insignia: five families of four ranks (bronze shield, silver hex,
 * gold star, crystal gem, royal crest), one more chevron for each rank inside
 * a family, and dots under it for the grade. Drawn as SVG, so nothing is
 * downloaded, unless the admin uploaded their own icon for the rank.
 */

const star = (cx: number, cy: number, r1: number, r2: number, n: number): string => {
  let d = '';
  for (let i = 0; i < n * 2; i++) {
    const r = i % 2 === 0 ? r1 : r2;
    const a = (Math.PI / n) * i - Math.PI / 2;
    d += (i === 0 ? 'M' : 'L') + (cx + r * Math.cos(a)).toFixed(1) + ' ' + (cy + r * Math.sin(a)).toFixed(1) + ' ';
  }
  return d + 'Z';
};

export { star };

// `bottom` is the family's colour (the admin can change it), `top` its light shade, `bar` the progress bar's end.
export const FAMILIES = [
  { key: 'bronze', top: '#e0a26a', bottom: '#8a5326', bar: '#e0a26a', path: 'M32 4 L56 13 V31 C56 46 45 55 32 61 C19 55 8 46 8 31 V13 Z' },
  { key: 'silver', top: '#eef2f6', bottom: '#7f8a98', bar: '#c6ced8', path: 'M32 3 L57 17.5 V46.5 L32 61 L7 46.5 V17.5 Z' },
  { key: 'gold', top: '#ffe08a', bottom: '#b8860b', bar: '#ffd43b', path: star(32, 33, 30, 21, 8) },
  { key: 'crystal', top: '#8af0e6', bottom: '#16736c', bar: '#63e6be', path: 'M32 3 L58 24 L32 61 L6 24 Z' },
  {
    key: 'royal',
    top: '#c9b6ff',
    bottom: '#4b2e98',
    bar: '#b197fc',
    path: 'M8 18 L18 27 L24 9 L32 21 L40 9 L46 27 L56 18 V36 C56 49 45 57 32 61 C19 57 8 49 8 36 Z',
  },
];

export type Family = (typeof FAMILIES)[number];

/** Mix a #rrggbb colour toward white by `amount` (0–1). */
const lighten = (hex: string, amount: number): string =>
  '#' +
  [1, 3, 5]
    .map((i) => {
      const c = parseInt(hex.slice(i, i + 2), 16);
      return Math.round(c + (255 - c) * amount)
        .toString(16)
        .padStart(2, '0');
    })
    .join('');

/** A rank's family (1–20), in the admin's colour when they picked one: its light shades are drawn from it. */
export const familyOf = (rank: number): Family => {
  const family = FAMILIES[Math.max(0, Math.min(4, Math.floor((rank - 1) / 4)))];
  // The admin page reads its saved settings, so its previews follow a save without a reload.
  const color =
    (app.data as any).settings?.['ernestdefoe-renown.color_' + family.key] ??
    app.forum?.attribute<string | null>('renownColor' + family.key[0].toUpperCase() + family.key.slice(1));
  if (typeof color !== 'string' || !/^#[0-9a-f]{6}$/i.test(color) || color.toLowerCase() === family.bottom) return family;

  const bottom = color.toLowerCase();

  return { ...family, bottom, top: lighten(bottom, 0.6), bar: lighten(bottom, 0.45) };
};

/** The progress bar's gradient for a family. */
export const fill = (family: Family): string => `linear-gradient(90deg, ${family.bottom}, ${family.bar})`;

const CHEVRONS = [22, 29.5, 37, 44.5];

let uid = 0;

export interface InsigniaAttrs {
  level: number;
  size?: number;
  /** Show the grade dots under the insignia. */
  grade?: boolean;
  /** Leave out the accessible name when the label is written beside it. */
  decorative?: boolean;
}

export default class Insignia extends Component<InsigniaAttrs> {
  id = 'renown-i' + ++uid;

  view(): Mithril.Children {
    const level = this.attrs.level || 1;
    const size = this.attrs.size || 22;
    const rank = rankOf(level);
    const family = familyOf(rank);
    const label = levelLabel(level);
    const a11y = this.attrs.decorative ? { 'aria-hidden': 'true' } : { role: 'img', 'aria-label': label };
    const icon = rankIcon(rank);

    const art = icon ? (
      <img className="RenownInsignia-img" src={icon} width={size} height={size} alt={this.attrs.decorative ? '' : label} loading="lazy" />
    ) : (
      <svg className="RenownInsignia-svg" width={size} height={size} viewBox="0 0 64 64" {...a11y}>
        <defs>
          <linearGradient id={this.id} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color={family.top} />
            <stop offset="1" stop-color={family.bottom} />
          </linearGradient>
        </defs>
        <path d={family.path} fill={`url(#${this.id})`} stroke="#0b0d11" stroke-width="2.5" />
        {CHEVRONS.slice(0, ((rank - 1) % 4) + 1).map((y) => (
          <polyline
            points={`21,${y} 32,${y + 7} 43,${y}`}
            fill="none"
            stroke="#fff"
            stroke-width="3.6"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        ))}
      </svg>
    );

    return (
      <span className={'RenownInsignia RenownInsignia--' + family.key} title={this.attrs.decorative ? undefined : label}>
        {art}
        {/* The family's darker shade: its light one vanished on a white page (silver). */}
        {this.attrs.grade ? (
          <span className="RenownInsignia-grade" aria-hidden="true">
            {[1, 2, 3, 4, 5].map((g) => (
              <span
                className={'RenownInsignia-pip' + (g <= gradeOf(level) ? ' is-on' : '')}
                style={g <= gradeOf(level) ? { background: family.bottom } : undefined}
              />
            ))}
          </span>
        ) : null}
      </span>
    );
  }
}
