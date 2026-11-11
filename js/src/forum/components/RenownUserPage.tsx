import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Switch from 'flarum/common/components/Switch';
import type Mithril from 'mithril';
import Insignia from '../../common/Insignia';
import Emblem from '../../common/Emblem';
import { ETERNAL, MAX_LEVEL, progress, rankOf, xpFor } from '../../common/curve';
import { levelLabel, tierName } from '../../common/names';
import PrestigeModal from './PrestigeModal';
import type { Row } from './Member';
import { api, number, t } from '../util';

declare const m: Mithril.Static;

type Profile = Row & {
  week: number;
  position: number | null;
  emblems: { tier: number; reachedAt: string }[];
  sources: Record<string, number>;
  canPrestige: boolean;
  hidden: boolean | null;
  canAdjust: boolean;
};

/**
 * The Renown tab on a profile: the insignia, progress to the next grade,
 * lifetime XP, this week, place on the board, the emblems earned and where
 * the XP came from in the last 30 days. On their own tab a member at Level
 * 100 can take Prestige, and can leave the board.
 */
export default class RenownUserPage extends UserPage {
  profile: Profile | null = null;
  private ticket = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.loadUser(m.route.param('username'));
  }

  show(user: any) {
    super.show(user);
    this.load();
  }

  load() {
    const ticket = ++this.ticket;
    api<{ data: Profile }>('GET', '/users/' + this.user!.id()).then((res) => {
      if (ticket !== this.ticket) return;
      this.profile = res.data;
      m.redraw();
    });
  }

  content() {
    const p = this.profile;
    if (!p) return <LoadingIndicator display="block" />;

    const eternal = p.prestige >= ETERNAL;
    const step = progress(p.level, p.xp);
    const sources = Object.entries(p.sources);
    const top = Math.max(1, ...sources.map(([, xp]) => xp));

    return (
      <div className="RenownProfile">
        <div className="RenownProfile-head">
          <Insignia level={p.level} size={112} grade decorative />
          <div className="RenownProfile-title">
            <h2 className="RenownProfile-rank">{levelLabel(p.level)}</h2>
            <p className="RenownProfile-sub">
              {t('profile.level_of', { level: p.level, max: MAX_LEVEL })}
              {p.prestige > 0 ? (
                <>
                  {' '}
                  ·{' '}
                  {t('profile.prestige', {
                    tier: p.prestige >= ETERNAL ? tierName(ETERNAL) : t('profile.prestige_tier', { n: p.prestige, name: tierName(p.prestige) }),
                  })}
                </>
              ) : null}
            </p>
          </div>
          {p.prestige > 0 ? <Emblem tier={p.prestige} size={64} /> : null}
        </div>

        {step ? (
          <div className="RenownProgress">
            <div className="RenownProgress-labels">
              <span>{t('profile.to_next', { rank: levelLabel(p.level + 1) })}</span>
              <span className="RenownProgress-numbers">{t('profile.progress', { xp: number(p.xp), next: number(step.next) })}</span>
            </div>
            <div className="RenownProgress-bar" role="progressbar" aria-valuemin="0" aria-valuemax={step.step} aria-valuenow={step.into}>
              <div
                className={'RenownProgress-fill RenownProgress-fill--' + Math.ceil(rankOf(p.level) / 4)}
                style={{ width: Math.min(100, (step.into / step.step) * 100) + '%' }}
              />
            </div>
          </div>
        ) : (
          <div className="RenownReady">
            {eternal ? (
              <p className="RenownReady-text">{t('profile.eternal', { top: levelLabel(MAX_LEVEL) })}</p>
            ) : p.canPrestige ? (
              <>
                <p className="RenownReady-text">
                  {t('profile.ready_self', { tier: tierName(p.prestige + 1), first: levelLabel(1), top: levelLabel(MAX_LEVEL) })}
                </p>
                <Button className="Button Button--primary" icon="fas fa-star" onclick={() => this.openPrestige()}>
                  {t(p.prestige + 1 >= ETERNAL ? 'prestige.button_eternal' : 'prestige.button')}
                </Button>
              </>
            ) : (
              <p className="RenownReady-text">{t('profile.ready_other')}</p>
            )}
            {p.xp > xpFor(MAX_LEVEL) && !eternal ? (
              <p className="RenownReady-banked">{t('profile.banked', { xp: number(p.xp - xpFor(MAX_LEVEL)) })}</p>
            ) : null}
          </div>
        )}

        <ul className="RenownStats">
          <li className="RenownStat">
            <span className="RenownStat-label">{t('profile.lifetime')}</span>
            <span className="RenownStat-value">{number(p.lifetime)}</span>
          </li>
          <li className="RenownStat">
            <span className="RenownStat-label">{t('profile.week')}</span>
            <span className="RenownStat-value">+{number(p.week)}</span>
          </li>
          <li className="RenownStat">
            <span className="RenownStat-label">{t('profile.position')}</span>
            <span className="RenownStat-value">{p.position ? '#' + number(p.position) : '–'}</span>
          </li>
        </ul>

        <section className="RenownSection">
          <h3 className="RenownSection-title">{t('profile.emblems')}</h3>
          {p.emblems.length ? (
            <ul className="RenownEmblems">
              {p.emblems.map((e) => (
                <li className="RenownEmblems-item">
                  <Emblem tier={e.tier} size={56} decorative />
                  <span className="RenownEmblems-name">{tierName(e.tier)}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="RenownSection-empty">{t('profile.no_emblems')}</p>
          )}
        </section>

        <section className="RenownSection">
          <h3 className="RenownSection-title">{t('profile.sources')}</h3>
          {sources.length ? (
            <ul className="RenownSources">
              {sources.map(([source, xp]) => (
                <li className="RenownSources-row">
                  <span className="RenownSources-label">{t('source.' + source)}</span>
                  <span className="RenownSources-bar">
                    <span style={{ width: Math.max(2, (xp / top) * 100) + '%' }} />
                  </span>
                  <span className="RenownSources-xp">{number(xp)}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="RenownSection-empty">{t('profile.no_sources')}</p>
          )}
        </section>

        {p.hidden !== null ? (
          <section className="RenownSection RenownSection--settings">
            <Switch state={!p.hidden} onchange={(on: boolean) => this.setHidden(!on)}>
              {t('profile.show_on_board')}
            </Switch>
          </section>
        ) : null}
      </div>
    );
  }

  openPrestige() {
    const p = this.profile!;
    app.modal.show(PrestigeModal, {
      tier: p.prestige + 1,
      onTaken: () => {
        this.profile = null;
        this.load();
      },
    });
  }

  setHidden(hidden: boolean) {
    this.profile!.hidden = hidden;
    api('PATCH', '/me', { hidden }).catch(() => {
      this.profile!.hidden = !hidden;
      m.redraw();
    });
  }
}
