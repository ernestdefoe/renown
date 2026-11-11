import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import { ETERNAL } from './curve';
import { tierIcon, tierName } from './names';
import { star } from './Insignia';

/*
 * The prestige emblem: a coloured ring and star for tiers 1–20, gaining a
 * ring and a point every five tiers; Eternal is its own gold sunburst.
 */

export const TIER_COLORS = [
  '#ff7a3d',
  '#ff9f1c',
  '#ffc53d',
  '#ff6b6b',
  '#74c0fc',
  '#4dabf7',
  '#63e6be',
  '#c3cad4',
  '#ffd43b',
  '#b197fc',
  '#9775fa',
  '#e599f7',
  '#66d9e8',
  '#ffa94d',
  '#a9e34b',
  '#91a7ff',
  '#ff8787',
  '#da77f2',
  '#748ffc',
  '#f1f3f5',
];

let uid = 0;

export interface EmblemAttrs {
  tier: number;
  size?: number;
  decorative?: boolean;
}

export default class Emblem extends Component<EmblemAttrs> {
  id = 'renown-e' + ++uid;

  view(): Mithril.Children {
    const tier = Math.max(1, Math.min(ETERNAL, this.attrs.tier));
    const size = this.attrs.size || 22;
    const label = tierName(tier);
    const a11y = this.attrs.decorative ? { 'aria-hidden': 'true' } : { role: 'img', 'aria-label': label };
    const icon = tierIcon(tier);

    if (icon) {
      return (
        <span className="RenownEmblem" title={this.attrs.decorative ? undefined : label}>
          <img src={icon} width={size} height={size} alt={this.attrs.decorative ? '' : label} loading="lazy" />
        </span>
      );
    }

    if (tier === ETERNAL) {
      return (
        <span className="RenownEmblem RenownEmblem--eternal" title={this.attrs.decorative ? undefined : label}>
          <svg width={size} height={size} viewBox="0 0 64 64" {...a11y}>
            <defs>
              <radialGradient id={this.id} cx="0.5" cy="0.45" r="0.6">
                <stop offset="0" stop-color="#fff6d6" />
                <stop offset="0.55" stop-color="#ffd43b" />
                <stop offset="1" stop-color="#b8860b" />
              </radialGradient>
            </defs>
            <path d={star(32, 32, 32, 24, 16)} fill="#ffd43b" opacity="0.35" />
            <circle cx="32" cy="32" r="24" fill="#12151b" stroke={`url(#${this.id})`} stroke-width="3" />
            <circle cx="32" cy="32" r="20" fill="none" stroke="#ffe08a" stroke-width="1" stroke-dasharray="1 2.5" />
            <path d={star(32, 32, 16, 7, 10)} fill={`url(#${this.id})`} />
          </svg>
        </span>
      );
    }

    const color = TIER_COLORS[tier - 1];
    const g = Math.floor((tier - 1) / 5) + 1;

    return (
      <span className="RenownEmblem" title={this.attrs.decorative ? undefined : label}>
        <svg width={size} height={size} viewBox="0 0 64 64" {...a11y}>
          <circle cx="32" cy="32" r="29" fill="#12151b" stroke={color} stroke-width="3.5" />
          {g >= 2 ? <circle cx="32" cy="32" r="24.5" fill="none" stroke={color} stroke-width="1.5" /> : null}
          {g >= 3 ? <circle cx="32" cy="32" r="21" fill="none" stroke={color} stroke-width="1" stroke-dasharray="2 3" /> : null}
          {g >= 4 ? <circle cx="32" cy="32" r="31" fill="none" stroke={color} stroke-width="1" stroke-dasharray="1 4" /> : null}
          <path d={star(32, 32, 17 + g, 8, 4 + g)} fill={color} />
          <circle cx="32" cy="32" r="3.2" fill="#12151b" />
        </svg>
      </span>
    );
  }
}
