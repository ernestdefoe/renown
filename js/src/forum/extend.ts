import Extend from 'flarum/common/extenders';
import UserPageResolver from 'flarum/forum/resolvers/UserPageResolver';

export default [
  // The leaderboard and the Renown tab are one chunk: it loads when somebody opens either, not with every page.
  new Extend.Routes()
    .add('renown', '/renown', () => import('./components').then((ui) => ({ default: ui.BoardPage })))
    .add('user.renown', '/u/:username/renown', () => import('./components').then((ui) => ({ default: ui.RenownUserPage })), UserPageResolver),
];
