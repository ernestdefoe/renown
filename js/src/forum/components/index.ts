/*
 * The one lazy chunk: the leaderboard, the Renown tab and the two modals.
 * One entry on purpose: two chunks sharing code would make webpack emit a
 * numbered shared chunk that jsDirectory doesn't publish.
 */
export { default as BoardPage } from './BoardPage';
export { default as RenownUserPage } from './RenownUserPage';
export { default as AdjustModal } from './AdjustModal';
export { default as PrestigeModal } from './PrestigeModal';
