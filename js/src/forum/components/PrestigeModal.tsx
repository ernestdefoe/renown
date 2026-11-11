import app from 'flarum/forum/app';
import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import Emblem from '../../common/Emblem';
import { ETERNAL, MAX_LEVEL } from '../../common/curve';
import { levelLabel, tierName } from '../../common/names';
import { api, t } from '../util';

interface Attrs extends IInternalModalAttrs {
  tier: number;
  onTaken: () => void;
}

/** "Take Prestige?": what resets, what stays. Always the member's own choice. */
export default class PrestigeModal extends Modal<Attrs> {
  loading = false;

  className() {
    return 'RenownPrestigeModal Modal--small';
  }

  title() {
    return this.attrs.tier >= ETERNAL ? t('prestige.title_eternal') : t('prestige.title', { tier: tierName(this.attrs.tier) });
  }

  content(): Mithril.Children {
    const eternal = this.attrs.tier >= ETERNAL;

    return (
      <div className="Modal-body">
        <div className="RenownPrestigeModal-emblem">
          <Emblem tier={this.attrs.tier} size={96} />
        </div>
        {eternal ? (
          <p>{t('prestige.eternal_text', { top: levelLabel(MAX_LEVEL) })}</p>
        ) : (
          <ul className="RenownPrestigeModal-list">
            <li>
              <strong>{t('prestige.resets')}</strong> {t('prestige.resets_text', { first: levelLabel(1) })}
            </li>
            <li>
              <strong>{t('prestige.keeps')}</strong> {t('prestige.keeps_text')}
            </li>
            <li>
              <strong>{t('prestige.earns')}</strong> {t('prestige.earns_text', { tier: tierName(this.attrs.tier) })}
            </li>
          </ul>
        )}
        <div className="Form-group RenownPrestigeModal-actions">
          <Button className="Button Button--primary" icon="fas fa-star" loading={this.loading} onclick={() => this.take()}>
            {t(eternal ? 'prestige.confirm_eternal' : 'prestige.confirm')}
          </Button>
          <Button className="Button" onclick={() => this.hide()}>
            {t('prestige.cancel')}
          </Button>
        </div>
      </div>
    );
  }

  take() {
    this.loading = true;
    api<{ data: any }>('POST', '/prestige').then(
      (res) => {
        const me = app.session.user;
        if (me) {
          me.pushAttributes({
            renownLevel: res.data.level,
            renownPrestige: res.data.prestige,
            renownXp: res.data.xp,
            renownSeen: res.data.seen,
          });
        }
        this.hide();
        app.alerts.show({ type: 'success' }, t('prestige.done', { tier: tierName(res.data.prestige) }));
        this.attrs.onTaken();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }
}

declare const m: Mithril.Static;
