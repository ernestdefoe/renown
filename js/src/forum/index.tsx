import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from 'flarum/common/components/Button';
import Page from 'flarum/common/components/Page';
import Notification from 'flarum/forum/components/Notification';
import UserControls from 'flarum/forum/utils/UserControls';
import Insignia from '../common/Insignia';
import Emblem from '../common/Emblem';
import { levelLabel, tierName } from '../common/names';
import { ETERNAL } from '../common/curve';
import BoostBanner from './BoostBanner';
import checkRankUp from './toast';
import { t } from './util';

export { default as extend } from './extend';

/*
 * forum.js is on every page, so it only draws the insignia (SVG, from the
 * user attributes already loaded) and wires things up. The leaderboard, the
 * Renown tab and the modals are one chunk fetched when first needed.
 */

class PrestigeReadyNotification extends Notification {
  icon() {
    return 'fas fa-star';
  }
  href() {
    const me = app.session.user;
    return me ? app.route('user.renown', { username: me.slug() }) : app.route('renown');
  }
  content() {
    return t('notification.prestige_ready');
  }
  excerpt() {
    return null;
  }
}

/** Rank, and the emblem once prestiged, for a user model. */
export function standing(user: any, size = 20, withText = true) {
  const level = user.attribute('renownLevel') || 1;
  const prestige = user.attribute('renownPrestige') || 0;
  return (
    <span className="RenownStanding">
      <Insignia level={level} size={size} decorative={withText} />
      {prestige > 0 ? <Emblem tier={prestige} size={size} decorative={withText} /> : null}
      {withText ? (
        <span className="RenownStanding-text">
          {levelLabel(level)}
          {prestige > 0 ? <span className="RenownStanding-tier"> · {tierName(Math.min(prestige, ETERNAL))}</span> : null}
        </span>
      ) : null}
    </span>
  );
}

app.initializers.add('ernestdefoe/renown', () => {
  app.notificationComponents.renownPrestigeReady = PrestigeReadyNotification;

  // String form: these components live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<any>) {
    if (!app.forum.attribute('renownNav')) return;
    items.add('renown', LinkButton.component({ href: app.route('renown'), icon: 'fas fa-medal' }, t('nav')), 72);
  });

  extend('flarum/forum/components/UserPage', 'navItems', function (this: any, items: ItemList<any>) {
    if (!this.user) return;
    items.add(
      'renown',
      LinkButton.component({ href: app.route('user.renown', { username: this.user.slug() }), icon: 'fas fa-medal' }, t('profile.tab')),
      74
    );
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    items.add('renownPrestigeReady', { name: 'renownPrestigeReady', icon: 'fas fa-star', label: t('notification.preference') });
  });

  // Beside the author's name on every post.
  extend('flarum/forum/components/CommentPost', 'headerItems', function (this: any, items: ItemList<any>) {
    const user = this.attrs.post.user?.();
    if (!user || !app.forum.attribute('renownOnPosts') || this.attrs.post.isHidden?.()) return;
    items.add(
      'renown',
      <a className="RenownPostBadge" href={app.route('user.renown', { username: user.slug() })}>
        {standing(user)}
      </a>,
      90
    );
  });

  extend('flarum/forum/components/UserCard', 'infoItems', function (this: any, items: ItemList<any>) {
    const user = this.attrs.user;
    if (!user) return;
    items.add(
      'renown',
      <span className="RenownCardInfo">
        {standing(user, 18)}
        <span className="RenownCardInfo-level">{t('card.level', { level: user.attribute('renownLevel') || 1 })}</span>
      </span>,
      85
    );
  });

  // A running Double XP event, above the discussion list.
  extend('flarum/forum/components/IndexPage', 'contentItems', function (items: ItemList<any>) {
    if ((app.forum.attribute<any[]>('renownBoosts') || []).length) items.add('renownBoost', <BoostBanner />, 104);
  });

  // Staff: add or remove XP by hand, with a reason.
  extend(UserControls, 'moderationControls', function (items: ItemList<any>, user: any) {
    if (!app.forum.attribute('renownCanAdjust')) return;
    items.add(
      'renownAdjust',
      Button.component(
        {
          icon: 'fas fa-medal',
          onclick: () => app.modal.show(() => import('./components').then((ui) => ui.AdjustModal), { user }),
        },
        t('adjust.control')
      )
    );
  });

  // "Ranked up" on the next page after a new level.
  extend(Page.prototype, 'oninit', checkRankUp);
});
