import app from 'flarum/forum/app';
import Insignia from '../common/Insignia';
import { levelLabel } from '../common/names';
import { api, t } from './util';

let shown = 0;

/**
 * The member's own level against the last one they were told about
 * (`renownSeen` = prestige × 1000 + level). A new level shows the toast
 * once; the first time ever (after the history replay) just records it.
 * Costs nothing until there is something to tell.
 */
export default function checkRankUp() {
  const me = app.session.user;
  if (!me) return;

  const level = me.attribute<number>('renownLevel') || 1;
  const prestige = me.attribute<number>('renownPrestige') || 0;
  const now = prestige * 1000 + level;
  const seen = me.attribute<number>('renownSeen') || 0;
  if (now <= seen || now === shown) return;
  shown = now;

  const tell = seen > 0 && Math.floor(seen / 1000) === prestige;
  if (tell) {
    const key = app.alerts.show(
      { type: 'success', className: 'RenownToast' },
      <span className="RenownToast-body">
        <Insignia level={level} size={44} decorative />
        <span className="RenownToast-text">
          <span className="RenownToast-kicker">{t('toast.ranked_up')}</span>
          <span className="RenownToast-title">{t('toast.title', { rank: levelLabel(level), level })}</span>
        </span>
      </span>
    );
    setTimeout(() => app.alerts.dismiss(key), 8000);
  }

  api('POST', '/seen').then(() => {
    me.pushAttributes({ renownSeen: now });
  });
}
