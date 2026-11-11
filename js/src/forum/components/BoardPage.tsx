import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { MemberCell, Standing, type Row } from './Member';
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

/**
 * /renown: the leaderboard. All time (prestige, then XP) or this month (XP
 * earned since the 1st). Core's page structure with the forum's sidebar, so
 * any theme dresses it.
 */
export default class BoardPage extends Page {
  period: 'all' | 'month' = 'all';
  rows: Row[] = [];
  page = 1;
  more = false;
  loading = true;
  ready = false;
  private ticket = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.period = m.route.param('period') === 'month' ? 'month' : 'all';
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
    api<{ data: Row[]; more: boolean }>('GET', `/board?period=${this.period}&page=${page}`).then(
      (res) => {
        if (ticket !== this.ticket) return;
        this.rows = page === 1 ? res.data : [...this.rows, ...res.data];
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

  switchTo(period: 'all' | 'month') {
    if (period === this.period) return;
    this.period = period;
    m.route.set(app.route('renown', period === 'month' ? { period } : {}), undefined, { replace: true } as any);
    this.load(1);
  }

  view() {
    if (!this.ready) return <LoadingIndicator display="block" />;

    return (
      <PageStructure className="RenownPage" sidebar={() => <IndexSidebar />}>
        <div className="RenownBoard">
          <div className="RenownBoard-head">
            <h2 className="RenownBoard-title">{t('board.title')}</h2>
            <div className="RenownBoard-tabs" role="tablist">
              {(['all', 'month'] as const).map((p) => (
                <Button
                  className={'Button' + (this.period === p ? ' Button--primary' : '')}
                  role="tab"
                  aria-selected={this.period === p ? 'true' : 'false'}
                  onclick={() => this.switchTo(p)}
                >
                  {t('board.period_' + p)}
                </Button>
              ))}
            </div>
          </div>
          {this.rows.length ? (
            <ol className="RenownBoard-list">
              {this.rows.map((row) => (
                <li className={'RenownBoard-row' + (app.session.user && Number(app.session.user.id()) === row.id ? ' is-me' : '')}>
                  <span className="RenownBoard-pos">{row.position}</span>
                  {MemberCell(row)}
                  <span className="RenownBoard-standing">{Standing(row)}</span>
                  <span
                    className="RenownBoard-xp"
                    title={this.period === 'all' && row.prestige > 0 ? tx('board.climb', { xp: number(row.xp) }) : undefined}
                  >
                    {this.period === 'month' ? (
                      t('board.gained', { xp: number(row.gained || 0) })
                    ) : (
                      <>
                        <span className="RenownBoard-lifetime">{t('board.xp', { xp: number(row.lifetime) })}</span>
                        {row.prestige > 0 ? <span className="RenownBoard-climb">{t('board.climb', { xp: number(row.xp) })}</span> : null}
                      </>
                    )}
                  </span>
                </li>
              ))}
            </ol>
          ) : this.loading ? null : (
            <p className="RenownBoard-empty">{t('board.empty')}</p>
          )}
          {this.loading ? <LoadingIndicator display="block" /> : null}
          {this.more && !this.loading ? (
            <div className="RenownBoard-more">
              <Button className="Button" onclick={() => this.load(this.page + 1)}>
                {t('board.more')}
              </Button>
            </div>
          ) : null}
        </div>
      </PageStructure>
    );
  }
}
