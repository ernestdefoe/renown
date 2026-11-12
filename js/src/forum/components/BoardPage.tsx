import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Avatar from 'flarum/common/components/Avatar';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { avatarUser, memberUrl, type Row } from './Member';
import Insignia, { FAMILIES } from '../../common/Insignia';
import Emblem, { TIER_COLORS } from '../../common/Emblem';
import { ETERNAL, MAX_LEVEL, progress, rankOf, xpFor } from '../../common/curve';
import { levelLabel, tierName } from '../../common/names';
import { api, number, t, tx } from '../util';

declare const m: Mithril.Static;
declare const flarum: any;

let PageStructure: any;
let IndexSidebar: any;

/** Core components this page builds on, through the registry: some live in lazy chunks. */
async function loadCore(): Promise<void> {
  if (PageStructure) return;
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

type Period = 'all' | 'month' | 'week' | 'prestiged';
const PERIODS: Period[] = ['all', 'month', 'week', 'prestiged'];

type Me = Row & { week: number; position: number | null; gap: { xp: number; position: number; name: string | null } | null };
type Board = { data: Row[]; more: boolean; rising: Row[]; me: Me | null };

const family = (level: number) => FAMILIES[Math.floor((rankOf(level) - 1) / 4)];
const ring = (row: Row): string | undefined => (row.prestige >= ETERNAL ? '#ffd43b' : row.prestige ? TIER_COLORS[row.prestige - 1] : undefined);
const compact = (n: number): string => new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(n || 0);

/** How far into this level: the bar's width and the line under it. */
function toNext(row: Row): { pct: number; label: Mithril.Children } {
  const step = progress(row.level, row.xp);
  if (step) {
    const pct = Math.min(100, Math.floor((step.into / step.step) * 100));
    return { pct, label: t('board.next', { pct, rank: levelLabel(row.level + 1) }) };
  }
  const banked = row.xp - xpFor(MAX_LEVEL);
  if (row.prestige >= ETERNAL) return { pct: 100, label: t('board.max_eternal') };
  return { pct: 100, label: banked > 0 ? t('board.banked', { xp: number(banked) }) : t('board.can_prestige') };
}

/**
 * /renown: the leaderboard. The top three stand on a stage (a dark surface of
 * its own, tinted with the forum's primary colour), everyone else follows as
 * cards, and a signed-in member off the page sees where they stand at the
 * bottom. Core's page structure with the forum's sidebar.
 */
export default class BoardPage extends Page {
  period: Period = 'all';
  rows: Row[] = [];
  rising: Row[] = [];
  me: Me | null = null;
  page = 1;
  more = false;
  loading = true;
  ready = false;
  private ticket = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    const period = m.route.param('period');
    this.period = PERIODS.includes(period) ? period : 'all';
    app.history.push('renown', tx('board.title'));
    this.bodyClass = 'App--renown';
    loadCore().then(() => {
      this.ready = true;
      m.redraw();
    });
    this.load(1);
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    app.setTitle(tx('board.title'));
    app.setTitleCount(0);
  }

  load(page: number) {
    const ticket = ++this.ticket;
    this.loading = true;
    if (page === 1) this.rows = [];
    api<Board>('GET', `/board?period=${this.period}&page=${page}`).then(
      (res) => {
        if (ticket !== this.ticket) return;
        this.rows = page === 1 ? res.data : [...this.rows, ...res.data];
        if (page === 1) {
          this.rising = res.rising || [];
          this.me = res.me;
        }
        this.page = page;
        this.more = res.more;
        this.loading = false;
        m.redraw();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }

  switchTo(period: Period) {
    if (period === this.period) return;
    this.period = period;
    m.route.set(app.route('renown', period === 'all' ? {} : { period }), undefined, { replace: true } as any);
    this.load(1);
  }

  get periodic(): boolean {
    return this.period === 'month' || this.period === 'week';
  }

  /** The figure a row is ranked by. */
  main(row: Row): string {
    return this.periodic ? tx('board.gained', { xp: number(row.gained || 0) }) : tx('board.xp', { xp: number(row.lifetime) });
  }

  view() {
    if (!this.ready) return <LoadingIndicator display="block" />;

    const top = this.rows.slice(0, 3);
    const rest = this.rows.slice(3);
    const empty = !this.loading && !this.rows.length;

    return (
      <PageStructure className="RenownPage" sidebar={() => <IndexSidebar />}>
        <div className="RenownBoard">
          <section className={'RenownStage' + (empty ? ' is-empty' : '')}>
            <span className="RenownStage-stars" aria-hidden="true" />
            <span className="RenownStage-beam" aria-hidden="true" />
            <div className="RenownStage-top">
              <div className="RenownStage-heading">
                <span className="RenownStage-kicker">{t('board.kicker_' + this.period)}</span>
                <h2 className="RenownStage-title">{empty ? t('board.empty_title_' + this.period) : t('board.title')}</h2>
                <p className="RenownStage-sub">{empty ? t('board.empty_' + this.period) : t('board.sub_' + this.period)}</p>
              </div>
              <div className="RenownStage-tabs" role="tablist">
                {PERIODS.map((p) => (
                  <button
                    type="button"
                    className={'RenownStage-tab' + (this.period === p ? ' is-active' : '')}
                    role="tab"
                    aria-selected={this.period === p ? 'true' : 'false'}
                    onclick={() => this.switchTo(p)}
                  >
                    {t('board.period_' + p)}
                  </button>
                ))}
              </div>
            </div>
            {empty && this.period === 'all' ? this.invite() : null}
            {this.loading && !this.rows.length ? (
              <LoadingIndicator display="block" />
            ) : (
              <div className="RenownPodium">{[0, 1, 2].map((i) => this.place(top[i], i + 1, empty))}</div>
            )}
          </section>

          {this.rising.length ? (
            <div className="RenownRising">
              <h3 className="RenownRising-title">{t('board.rising')}</h3>
              <div className="RenownRising-chips">
                {this.rising.map((row) => (
                  <Link className="RenownRising-chip" href={memberUrl(row)}>
                    <Avatar user={avatarUser(row)} className="RenownRising-avatar" />
                    <span className="RenownRising-name">{row.displayName}</span>
                    <em>{t('board.week_short', { xp: number(row.week || 0) })}</em>
                  </Link>
                ))}
              </div>
            </div>
          ) : null}

          {rest.length ? <ol className="RenownBoard-list">{rest.map((row) => this.row(row))}</ol> : null}
          {this.loading && this.rows.length ? <LoadingIndicator display="block" /> : null}
          {this.more && !this.loading ? (
            <div className="RenownBoard-more">
              <Button className="Button" onclick={() => this.load(this.page + 1)}>
                {t('board.more')}
              </Button>
            </div>
          ) : null}
          {this.you()}
        </div>
      </PageStructure>
    );
  }

  /** A place on the podium, or an open spot. */
  place(row: Row | undefined, place: number, empty: boolean): Mithril.Children {
    const order = [2, 1, 3][place - 1];
    if (!row) {
      return (
        <div className={'RenownPodium-place is-' + place + ' is-open'} style={{ order }}>
          <span className="RenownPodium-ghost" aria-hidden="true">
            {empty ? '?' : '+'}
          </span>
          {empty ? null : (
            <>
              <span className="RenownPodium-name">{t('board.open_spot')}</span>
              <span className="RenownPodium-open">{t('board.open_hint_' + this.period)}</span>
            </>
          )}
          <span className="RenownPodium-plinth">
            <b>{place}</b>
          </span>
        </div>
      );
    }

    const color = ring(row) || family(row.level).top;
    const spin = place === 1 || row.prestige >= 16;
    const week = row.week || 0;

    return (
      <Link className={'RenownPodium-place is-' + place} href={memberUrl(row)} style={{ order, '--ring': color }}>
        {place === 1 ? (
          <svg className="RenownPodium-crown" width="34" height="24" viewBox="0 0 34 24" aria-hidden="true">
            <path d="M2 8 L9 14 L17 2 L25 14 L32 8 L29 22 H5 Z" fill="currentColor" />
          </svg>
        ) : null}
        <span className="RenownPodium-avatar">
          <span className="RenownPodium-halo" aria-hidden="true" />
          {spin ? <span className="RenownPodium-spin" aria-hidden="true" /> : null}
          <Avatar user={avatarUser(row)} className="RenownPodium-img" />
          <span className="RenownPodium-insignia">
            <Insignia level={row.level} size={place === 1 ? 56 : 46} decorative />
          </span>
        </span>
        <span className="RenownPodium-name">{row.displayName}</span>
        <span className="RenownPodium-rank">
          <span>{levelLabel(row.level)}</span>
          {row.prestige > 0 ? (
            <span className="RenownPodium-tier">
              <Emblem tier={row.prestige} size={18} decorative />
              <span>{tierName(row.prestige)}</span>
            </span>
          ) : null}
        </span>
        <span className="RenownPodium-xp">
          <span className="RenownBoard-full">{this.main(row)}</span>
          <span className="RenownBoard-short">{this.periodic ? '+' + compact(row.gained || 0) : compact(row.lifetime)}</span>
        </span>
        <span className={'RenownPodium-sub' + (!this.periodic && !week ? ' is-quiet' : '')}>
          {this.periodic ? t('board.lifetime', { xp: number(row.lifetime) }) : week ? t('board.this_week', { xp: number(week) }) : t('board.quiet')}
        </span>
        <span className="RenownPodium-plinth">
          <b>{place}</b>
        </span>
      </Link>
    );
  }

  row(row: Row): Mithril.Children {
    const fam = family(row.level);
    const next = toNext(row);
    const week = row.week || 0;
    const mine = app.session.user && Number(app.session.user.id()) === row.id;

    return (
      <li className={'RenownRow' + (mine ? ' is-me' : '')} style={{ '--ring': ring(row) || 'transparent', '--fam': fam.bottom }}>
        <span className="RenownRow-pos">{row.position}</span>
        <Link className="RenownRow-member" href={memberUrl(row)}>
          <Avatar user={avatarUser(row)} className="RenownRow-avatar" />
          <span className="RenownRow-who">
            <span className="RenownRow-name">{row.displayName}</span>
            <span className="RenownRow-rankline">
              <span className="RenownRow-rank">
                <Insignia level={row.level} size={20} decorative />
                <span>{levelLabel(row.level)}</span>
              </span>
              {row.prestige > 0 ? (
                <span className="RenownRow-tier">
                  <Emblem tier={row.prestige} size={16} decorative />
                  <span>{tierName(row.prestige)}</span>
                </span>
              ) : null}
            </span>
          </span>
        </Link>
        <span className="RenownRow-progress">
          <span className="RenownBar">
            <span className="RenownBar-fill" style={{ width: next.pct + '%', background: `linear-gradient(90deg, ${fam.bottom}, ${fam.top})` }} />
          </span>
          <span className="RenownRow-next">{next.label}</span>
        </span>
        <span className={'RenownRow-trend' + (this.periodic || !week ? ' is-quiet' : '')}>
          {this.periodic
            ? t('board.lifetime', { xp: number(row.lifetime) })
            : week
              ? t('board.week_short', { xp: number(week) })
              : t('board.quiet_short')}
        </span>
        <span className="RenownRow-xp">
          <b>
            <span className="RenownBoard-full">{this.main(row)}</span>
            <span className="RenownBoard-short">{this.periodic ? '+' + compact(row.gained || 0) : compact(row.lifetime)}</span>
          </b>
          {!this.periodic && row.prestige > 0 ? <small>{t('board.climb', { xp: number(row.xp) })}</small> : null}
        </span>
      </li>
    );
  }

  /** The viewer's own standing, pinned at the bottom while they aren't on the board above. */
  you(): Mithril.Children {
    const me = this.me;
    if (!me || this.rows.some((row) => row.id === me.id) || (this.loading && !this.rows.length)) return null;

    const next = toNext(me);
    let line: Mithril.Children;
    if (me.gap) {
      line = me.gap.name
        ? t('board.pass', { xp: number(me.gap.xp), name: me.gap.name, position: me.gap.position })
        : t('board.reach', { xp: number(me.gap.xp), position: me.gap.position });
    } else if (this.period === 'prestiged' && !me.prestige) {
      line = me.level >= MAX_LEVEL ? t('board.prestige_ready') : t('board.prestige_opens', { levels: MAX_LEVEL - me.level });
    } else if (!me.position) {
      line = t('board.earn_' + this.period);
    } else {
      line = t('board.next_prestige');
    }

    return (
      <div className="RenownYou">
        <span className="RenownYou-pos">{me.position ? '#' + me.position : '–'}</span>
        <span className="RenownYou-member">
          <Avatar user={avatarUser(me)} className="RenownYou-avatar" />
          <span className="RenownYou-who">
            <span className="RenownYou-name">{t('board.you')}</span>
            <span className="RenownYou-rank">
              {levelLabel(me.level)} ·{' '}
              {this.periodic ? t('board.gained', { xp: number(me.gained || 0) }) : t('board.xp', { xp: number(me.lifetime) })}
            </span>
          </span>
        </span>
        <span className="RenownYou-progress">
          <span className="RenownBar">
            <span className="RenownBar-fill" style={{ width: next.pct + '%' }} />
          </span>
          <span className="RenownYou-line">{line}</span>
        </span>
        <Link className="RenownYou-link" href={memberUrl(me)}>
          {t('board.my_renown')}
        </Link>
      </div>
    );
  }

  /** On an empty board: start the first discussion, or log in to. */
  invite(): Mithril.Children {
    const guest = !app.session.user;
    return (
      <button type="button" className="RenownStage-cta" onclick={() => this.start()}>
        {guest ? app.translator.trans('core.forum.header.log_in_link') : app.translator.trans('core.forum.index.start_discussion_button')}
      </button>
    );
  }

  async start() {
    if (!app.session.user) {
      const modal = await flarum.reg.asyncModuleImport('flarum/forum/components/LogInModal');
      app.modal.show(modal.default || modal);
      return;
    }
    const composer = await flarum.reg.asyncModuleImport('flarum/forum/components/DiscussionComposer');
    app.composer.load(composer.default || composer, { user: app.session.user });
    app.composer.show();
  }
}
