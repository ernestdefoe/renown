import app from 'flarum/common/app';
import { gradeOf, rankOf } from './curve';

const ROMAN = ['I', 'II', 'III', 'IV', 'V'];

type Entry = { name: string; icon: string | null };

const custom = (kind: 'ranks' | 'tiers', step: number): Entry | undefined => app.forum?.attribute<any>('renown')?.[kind]?.[step - 1];

/** The admin's name for a rank (1–20), else the language pack's. */
export const rankName = (rank: number): string =>
  custom('ranks', rank)?.name || String(app.translator.trans(`ernestdefoe-renown.lib.ranks.${rank}`, {}, true));

/** A prestige tier, 1–20, or 21 for Eternal. */
export const tierName = (tier: number): string =>
  custom('tiers', tier)?.name || String(app.translator.trans(`ernestdefoe-renown.lib.tiers.${tier}`, {}, true));

/** "Veteran II" */
export const levelLabel = (level: number): string => rankName(rankOf(level)) + ' ' + ROMAN[gradeOf(level) - 1];

export const rankIcon = (rank: number): string | null => custom('ranks', rank)?.icon || null;

export const tierIcon = (tier: number): string | null => custom('tiers', tier)?.icon || null;
