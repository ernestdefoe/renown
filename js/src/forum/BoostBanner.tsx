import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import { t } from './util';

/** "Double XP until Sunday 8 PM" while an event runs (the next one, a day ahead). */
export default class BoostBanner extends Component {
  view() {
    const now = Date.now();
    const boosts = app.forum.attribute<any[]>('renownBoosts') || [];
    const running = boosts.find((b) => Date.parse(b.starts_at) <= now && Date.parse(b.ends_at) > now);
    const soon = !running && boosts.find((b) => Date.parse(b.starts_at) > now && Date.parse(b.starts_at) - now < 86400000);
    const boost = running || soon;
    if (!boost) return null;

    const when = (iso: string) => new Date(iso).toLocaleString(undefined, { weekday: 'long', hour: 'numeric', minute: '2-digit' });
    const tag = boost.tag_id ? app.store.getById<any>('tags', String(boost.tag_id)) : null;

    return (
      <div className={'RenownBoost' + (running ? ' is-running' : '')}>
        <Icon name="fas fa-bolt" />
        <span className="RenownBoost-text">
          <strong>{boost.title || t('boost.title_' + boost.multiplier)}</strong>{' '}
          {running ? t('boost.until', { time: when(boost.ends_at) }) : t('boost.starts', { time: when(boost.starts_at) })}
          {tag ? <> · {t('boost.in_tag', { tag: tag.name() })}</> : null}
        </span>
      </div>
    );
  }
}
