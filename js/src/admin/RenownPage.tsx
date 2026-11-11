import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Form from 'flarum/common/components/Form';
import isExtensionEnabled from 'flarum/admin/utils/isExtensionEnabled';
import type Mithril from 'mithril';
import Insignia from '../common/Insignia';
import Emblem from '../common/Emblem';
import { ETERNAL, RANKS, xpFor } from '../common/curve';

declare const m: Mithril.Static;

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-renown.admin.${key}`, params);
const tx = (key: string, params: Record<string, any> = {}): string => String(app.translator.trans(`ernestdefoe-renown.admin.${key}`, params, true));
const lib = (key: string): string => String(app.translator.trans(`ernestdefoe-renown.lib.${key}`, {}, true));
const KEY = (k: string) => `ernestdefoe-renown.${k}`;
const PACKS = ['classic', 'military', 'fantasy', 'sports'];

type Entry = { name: string; group: number | null; permanent: boolean };
type State = {
  replay: { phase: string; progress: number; finishedAt: string | null } | null;
  ranks: number[];
  members: number;
  prestiged: number;
  ready: number;
  boosts: { id: number; title: string | null; multiplier: number; starts_at: string; ends_at: string; tag_id: number | null }[];
};

const SOURCES: { key: string; needs?: string }[] = [
  { key: 'reply' },
  { key: 'discussion' },
  { key: 'like', needs: 'flarum-likes' },
  { key: 'best_answer' },
  { key: 'visit' },
  { key: 'streak', needs: 'ernestdefoe-cadence' },
  { key: 'kudos', needs: 'ernestdefoe-kudos' },
  { key: 'answer_upvote', needs: 'ernestdefoe-answers' },
];

const enabled = (id: string): boolean => isExtensionEnabled(id);

/**
 * Renown's admin page: where the history replay is (it runs from here too,
 * a slice a second, so forums without cron finish), how members spread over
 * the ranks, the XP each thing earns, the anti-farming rules, the ranks and
 * prestige tiers (names, icons, group rewards), and Double XP events.
 */
export default class RenownPage extends ExtensionPage {
  state: State | null = null;
  ranks: Entry[] = [];
  tiers: Entry[] = [];
  icons: { ranks: Record<number, string | null>; tiers: Record<number, string | null> } = { ranks: {}, tiers: {} };
  pack = 'classic';
  boost = { title: '', multiplier: '2', startsAt: '', endsAt: '', tagId: '' };
  busy = false;
  private polling = false;
  private gone = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.ranks = this.entries('ranks', RANKS);
    this.tiers = this.entries('tiers', ETERNAL);
    const forum = app.forum.attribute<any>('renown') || {};
    (['ranks', 'tiers'] as const).forEach((kind) => (forum[kind] || []).forEach((e: any, i: number) => (this.icons[kind][i + 1] = e.icon)));
    this.load();
  }

  onremove(vnode: any) {
    super.onremove(vnode);
    this.gone = true;
  }

  entries(kind: string, count: number): Entry[] {
    let saved: any[] = [];
    try {
      saved = JSON.parse(this.setting(KEY(kind))() || '[]') || [];
    } catch {}
    return Array.from({ length: count }, (_, i) => ({
      name: saved[i]?.name || '',
      group: saved[i]?.group || null,
      permanent: !!saved[i]?.permanent,
    }));
  }

  /** The two lists live in the page until Save, then go in their settings as JSON. */
  store(kind: 'ranks' | 'tiers') {
    this.setting(KEY(kind))(JSON.stringify(this[kind]));
  }

  load(action?: string) {
    const req = action
      ? app.request<{ data: State }>({ method: 'POST', url: app.forum.attribute('apiUrl') + '/renown/admin', body: { action } })
      : app.request<{ data: State }>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/renown/admin' });
    return req.then((res) => {
      this.state = res.data;
      this.busy = false;
      m.redraw();
      this.poll();
    });
  }

  /** While a replay waits, keep it moving: a slice per call, one call at a time, while this page is open. */
  poll() {
    const r = this.state?.replay;
    if (this.polling || this.gone || !r || r.phase === 'done') return;
    this.polling = true;
    this.load('run').then(
      () => {
        this.polling = false;
        setTimeout(() => this.poll(), 400);
      },
      () => (this.polling = false)
    );
  }

  content() {
    return (
      <div className="ExtensionPage-settings RenownAdmin">
        <div className="container">
          {this.statusSection()}
          <Form>
            {this.sourcesSection()}
            {this.rulesSection()}
            {this.ranksSection()}
            {this.tiersSection()}
            {this.displaySection()}
            <div className="Form-group Form-controls">{this.submitButton()}</div>
          </Form>
          {this.boostsSection()}
        </div>
      </div>
    );
  }

  statusSection() {
    const s = this.state;
    if (!s) return <LoadingIndicator display="block" />;
    const r = s.replay;
    const max = Math.max(1, ...s.ranks);

    return (
      <section className="RenownAdmin-section">
        <h3>{t('status.heading')}</h3>
        <p className="helpText">{t('status.replay_help')}</p>
        {r && r.phase !== 'done' ? (
          <div className="RenownAdmin-replay">
            <div className="RenownAdmin-bar">
              <span style={{ width: r.progress + '%' }} />
            </div>
            <p>{t('status.replaying', { progress: r.progress })}</p>
          </div>
        ) : (
          <p className="RenownAdmin-done">{t('status.replayed')}</p>
        )}
        <ul className="RenownAdmin-figures">
          <li>
            <strong>{s.members.toLocaleString()}</strong> {t('status.members')}
          </li>
          <li>
            <strong>{s.ready.toLocaleString()}</strong> {t('status.ready')}
          </li>
          <li>
            <strong>{s.prestiged.toLocaleString()}</strong> {t('status.prestiged')}
          </li>
        </ul>
        <ol className="RenownAdmin-ranks">
          {s.ranks.map((count, i) => (
            <li>
              <Insignia level={i * 5 + 1} size={20} decorative />
              <span className="RenownAdmin-rankName">{this.ranks[i].name || lib('ranks.' + (i + 1))}</span>
              <span className="RenownAdmin-rankBar">
                <span style={{ width: (count / max) * 100 + '%' }} />
              </span>
              <span className="RenownAdmin-rankCount">{count.toLocaleString()}</span>
            </li>
          ))}
        </ol>
        <div className="RenownAdmin-actions">
          <Button className="Button" icon="fas fa-clock-rotate-left" loading={this.busy} onclick={() => this.act('rebuild')}>
            {t('status.rebuild')}
          </Button>
          <Button className="Button Button--danger" icon="fas fa-eraser" loading={this.busy} onclick={() => this.act('reset')}>
            {t('status.reset')}
          </Button>
        </div>
      </section>
    );
  }

  act(action: 'rebuild' | 'reset') {
    if (!confirm(tx('status.confirm_' + action))) return;
    this.busy = true;
    this.load(action);
  }

  sourcesSection() {
    return (
      <section className="RenownAdmin-section">
        <h3>{t('sources.heading')}</h3>
        <p className="helpText">{t('sources.help')}</p>
        <div className="RenownAdmin-grid">
          {SOURCES.map(({ key, needs }) => {
            const off = needs && !enabled(needs);
            return (
              <div className={'Form-group' + (off ? ' is-off' : '')}>
                <label for={'renown-xp-' + key}>{t('sources.' + key)}</label>
                <input id={'renown-xp-' + key} className="FormControl" type="number" min="0" max="10000" bidi={this.setting(KEY('xp_' + key))} />
                {off ? <p className="helpText">{t('sources.needs', { extension: t('extensions.' + needs) })}</p> : null}
              </div>
            );
          })}
        </div>
      </section>
    );
  }

  rulesSection() {
    const tags = app.store.all('tags') as any[];
    let excluded: number[] = [];
    try {
      excluded = JSON.parse(this.setting(KEY('exclude_tags'))() || '[]') || [];
    } catch {}
    const toggleTag = (id: number, on: boolean) => {
      const next = on ? [...new Set([...excluded, id])] : excluded.filter((x) => x !== id);
      this.setting(KEY('exclude_tags'))(JSON.stringify(next));
    };

    return (
      <section className="RenownAdmin-section">
        <h3>{t('rules.heading')}</h3>
        <p className="helpText">{t('rules.help')}</p>
        <div className="RenownAdmin-grid">
          {['cap_daily', 'cap_likes', 'likes_per_giver', 'min_length', 'cooldown'].map((key) => (
            <div className="Form-group">
              <label for={'renown-' + key}>{t('rules.' + key)}</label>
              <input id={'renown-' + key} className="FormControl" type="number" min="0" max="100000" bidi={this.setting(KEY(key))} />
              <p className="helpText">{t('rules.' + key + '_help')}</p>
            </div>
          ))}
        </div>
        {tags.length ? (
          <div className="Form-group">
            <label>{t('rules.exclude_tags')}</label>
            <p className="helpText">{t('rules.exclude_tags_help')}</p>
            <div className="RenownAdmin-tags">
              {tags.map((tag) => {
                const id = Number(tag.id());
                return (
                  <label className="checkbox">
                    <input
                      type="checkbox"
                      checked={excluded.includes(id)}
                      onchange={(e: Event) => toggleTag(id, (e.target as HTMLInputElement).checked)}
                    />{' '}
                    {tag.name()}
                  </label>
                );
              })}
            </div>
          </div>
        ) : null}
      </section>
    );
  }

  groupOptions(): Record<string, string> {
    // Keys like "g5": a plain "5" sorts ahead of "" in an object, which would put "No group" last.
    const options: Record<string, string> = { '': tx('ranks.no_group') };
    (app.store.all('groups') as any[]).filter((g) => Number(g.id()) > 4).forEach((g) => (options['g' + g.id()] = g.namePlural()));
    return options;
  }

  ranksSection() {
    const groups = this.groupOptions();
    const packOptions = Object.fromEntries(PACKS.map((p) => [p, tx('packs.' + p + '.label')]));

    return (
      <section className="RenownAdmin-section">
        <h3>{t('ranks.heading')}</h3>
        <p className="helpText">{t('ranks.help')}</p>
        <div className="RenownAdmin-pack">
          <Select value={this.pack} options={packOptions} onchange={(v: string) => (this.pack = v)} />
          <Button className="Button" onclick={() => this.usePack()}>
            {t('ranks.use_pack')}
          </Button>
        </div>
        <table className="RenownAdmin-table">
          <thead>
            <tr>
              <th>{t('ranks.rank')}</th>
              <th>{t('ranks.name')}</th>
              <th>{t('ranks.icon')}</th>
              <th>{t('ranks.reward')}</th>
            </tr>
          </thead>
          <tbody>
            {this.ranks.map((entry, i) => (
              <tr>
                <td className="RenownAdmin-art">
                  <Insignia level={i * 5 + 1} size={32} decorative />
                  <span className="RenownAdmin-step">
                    {t('ranks.levels', { from: i * 5 + 1, to: i * 5 + 5, xp: xpFor(i * 5 + 1).toLocaleString() })}
                  </span>
                </td>
                <td>
                  <input
                    className="FormControl"
                    maxlength={40}
                    placeholder={lib('ranks.' + (i + 1))}
                    value={entry.name}
                    aria-label={tx('ranks.name')}
                    oninput={(e: InputEvent) => {
                      entry.name = (e.target as HTMLInputElement).value;
                      this.store('ranks');
                    }}
                  />
                </td>
                <td>{this.iconCell('ranks', i + 1)}</td>
                <td>{this.rewardCell('ranks', entry, groups)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    );
  }

  tiersSection() {
    const groups = this.groupOptions();

    return (
      <section className="RenownAdmin-section">
        <h3>{t('tiers.heading')}</h3>
        <p className="helpText">{t('tiers.help')}</p>
        <table className="RenownAdmin-table">
          <thead>
            <tr>
              <th>{t('tiers.tier')}</th>
              <th>{t('ranks.name')}</th>
              <th>{t('ranks.icon')}</th>
              <th>{t('ranks.reward')}</th>
            </tr>
          </thead>
          <tbody>
            {this.tiers.map((entry, i) => (
              <tr>
                <td className="RenownAdmin-art">
                  <Emblem tier={i + 1} size={32} decorative />
                  <span className="RenownAdmin-step">{i + 1 === ETERNAL ? t('tiers.eternal') : t('tiers.number', { n: i + 1 })}</span>
                </td>
                <td>
                  <input
                    className="FormControl"
                    maxlength={40}
                    placeholder={lib('tiers.' + (i + 1))}
                    value={entry.name}
                    aria-label={tx('ranks.name')}
                    oninput={(e: InputEvent) => {
                      entry.name = (e.target as HTMLInputElement).value;
                      this.store('tiers');
                    }}
                  />
                </td>
                <td>{this.iconCell('tiers', i + 1)}</td>
                <td>{this.rewardCell('tiers', entry, groups)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    );
  }

  rewardCell(kind: 'ranks' | 'tiers', entry: Entry, groups: Record<string, string>) {
    return (
      <div className="RenownAdmin-reward">
        <Select
          value={entry.group ? 'g' + entry.group : ''}
          options={groups}
          onchange={(v: string) => {
            entry.group = v ? Number(v.slice(1)) : null;
            this.store(kind);
          }}
        />
        {entry.group ? (
          <Switch
            state={entry.permanent}
            onchange={(on: boolean) => {
              entry.permanent = on;
              this.store(kind);
            }}
          >
            {t('ranks.permanent')}
          </Switch>
        ) : null}
      </div>
    );
  }

  iconCell(kind: 'ranks' | 'tiers', step: number) {
    const url = this.icons[kind][step];
    const id = `renown-icon-${kind}-${step}`;
    return (
      <div className="RenownAdmin-icon">
        {url ? <img src={url} width="32" height="32" alt="" /> : null}
        <label className="Button Button--icon RenownAdmin-upload" for={id} title={tx('ranks.upload')} aria-label={tx('ranks.upload')}>
          <i className="fas fa-upload" aria-hidden="true" />
        </label>
        <input
          id={id}
          type="file"
          accept="image/png,image/jpeg,image/webp,image/gif"
          className="RenownAdmin-file"
          onchange={(e: Event) => this.upload(kind, step, e)}
        />
        {url ? (
          <Button
            className="Button Button--icon"
            icon="fas fa-times"
            aria-label={tx('ranks.remove_icon')}
            title={tx('ranks.remove_icon')}
            onclick={() => this.removeIcon(kind, step)}
          />
        ) : null}
      </div>
    );
  }

  upload(kind: 'ranks' | 'tiers', step: number, e: Event) {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    const body = new FormData();
    body.append('icon', file);
    app
      .request<{ data: { url: string } }>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/renown/icons/${kind}/${step}`,
        serialize: (raw: any) => raw,
        body,
      })
      .then((res) => {
        this.icons[kind][step] = res.data.url;
        input.value = '';
        m.redraw();
      });
  }

  removeIcon(kind: 'ranks' | 'tiers', step: number) {
    app.request({ method: 'DELETE', url: `${app.forum.attribute('apiUrl')}/renown/icons/${kind}/${step}` }).then(() => {
      this.icons[kind][step] = null;
      m.redraw();
    });
  }

  usePack() {
    this.ranks.forEach((entry, i) => (entry.name = this.pack === 'classic' ? '' : tx(`packs.${this.pack}.${i + 1}`)));
    this.store('ranks');
  }

  displaySection() {
    return (
      <section className="RenownAdmin-section">
        <h3>{t('display.heading')}</h3>
        {this.buildSettingComponent({ setting: KEY('on_posts'), type: 'boolean', label: tx('display.on_posts') })}
        {this.buildSettingComponent({ setting: KEY('nav'), type: 'boolean', label: tx('display.nav') })}
      </section>
    );
  }

  boostsSection() {
    const boosts = this.state?.boosts || [];
    const tags = app.store.all('tags') as any[];
    const tagOptions: Record<string, string> = {
      '': tx('boosts.everywhere'),
      ...Object.fromEntries(tags.map((tag) => ['t' + tag.id(), tag.name()])),
    };
    const when = (iso: string) => new Date(iso).toLocaleString();

    return (
      <section className="RenownAdmin-section">
        <h3>{t('boosts.heading')}</h3>
        <p className="helpText">{t('boosts.help')}</p>
        {boosts.length ? (
          <ul className="RenownAdmin-boosts">
            {boosts.map((b) => (
              <li>
                <strong>{b.title || tx('boosts.multiplier_' + b.multiplier)}</strong>{' '}
                {t('boosts.window', { start: when(b.starts_at), end: when(b.ends_at) })}
                {b.tag_id ? ' · ' + (app.store.getById<any>('tags', String(b.tag_id))?.name() || '') : ''}
                <Button className="Button Button--link" onclick={() => this.deleteBoost(b.id)}>
                  {t('boosts.delete')}
                </Button>
              </li>
            ))}
          </ul>
        ) : (
          <p>{t('boosts.none')}</p>
        )}
        <div className="RenownAdmin-grid">
          <div className="Form-group">
            <label for="renown-boost-title">{t('boosts.title')}</label>
            <input
              id="renown-boost-title"
              className="FormControl"
              maxlength={100}
              value={this.boost.title}
              oninput={(e: any) => (this.boost.title = e.target.value)}
            />
          </div>
          <div className="Form-group">
            <label>{t('boosts.multiplier')}</label>
            <Select
              value={this.boost.multiplier}
              options={{ '2': tx('boosts.multiplier_2'), '3': tx('boosts.multiplier_3') }}
              onchange={(v: string) => (this.boost.multiplier = v)}
            />
          </div>
          <div className="Form-group">
            <label for="renown-boost-start">{t('boosts.starts')}</label>
            <input
              id="renown-boost-start"
              className="FormControl"
              type="datetime-local"
              value={this.boost.startsAt}
              oninput={(e: any) => (this.boost.startsAt = e.target.value)}
            />
          </div>
          <div className="Form-group">
            <label for="renown-boost-end">{t('boosts.ends')}</label>
            <input
              id="renown-boost-end"
              className="FormControl"
              type="datetime-local"
              value={this.boost.endsAt}
              oninput={(e: any) => (this.boost.endsAt = e.target.value)}
            />
          </div>
          {tags.length ? (
            <div className="Form-group">
              <label>{t('boosts.tag')}</label>
              <Select value={this.boost.tagId} options={tagOptions} onchange={(v: string) => (this.boost.tagId = v)} />
            </div>
          ) : null}
        </div>
        <Button
          className="Button Button--primary"
          icon="fas fa-bolt"
          disabled={!this.boost.startsAt || !this.boost.endsAt}
          onclick={() => this.addBoost()}
        >
          {t('boosts.add')}
        </Button>
      </section>
    );
  }

  addBoost() {
    const b = this.boost;
    app
      .request<{ data: State['boosts'] }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/renown/boosts',
        body: {
          title: b.title,
          multiplier: Number(b.multiplier),
          // The admin's own clock: datetime-local has no zone, the browser knows it.
          startsAt: new Date(b.startsAt).toISOString(),
          endsAt: new Date(b.endsAt).toISOString(),
          tagId: b.tagId ? Number(b.tagId.slice(1)) : null,
        },
      })
      .then((res) => {
        if (this.state) this.state.boosts = res.data;
        this.boost = { title: '', multiplier: '2', startsAt: '', endsAt: '', tagId: '' };
        m.redraw();
      });
  }

  deleteBoost(id: number) {
    app.request<{ data: State['boosts'] }>({ method: 'DELETE', url: app.forum.attribute('apiUrl') + '/renown/boosts/' + id }).then((res) => {
      if (this.state) this.state.boosts = res.data;
      m.redraw();
    });
  }
}
