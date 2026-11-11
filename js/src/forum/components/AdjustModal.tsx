import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import { levelLabel } from '../../common/names';
import { api, number, t } from '../util';

interface Attrs extends IFormModalAttrs {
  user: any;
}

/** Staff add or remove XP by hand. The reason is kept in the member's log. */
export default class AdjustModal extends FormModal<Attrs> {
  xp = Stream('');
  reason = Stream('');

  className() {
    return 'RenownAdjustModal Modal--small';
  }

  title() {
    return t('adjust.title', { username: this.attrs.user.displayName() });
  }

  content(): Mithril.Children {
    const user = this.attrs.user;

    return (
      <div className="Modal-body">
        <p className="helpText">
          {t('adjust.now', { rank: levelLabel(user.attribute('renownLevel') || 1), xp: number(user.attribute('renownXp') || 0) })}
        </p>
        <div className="Form-group">
          <label for="renown-adjust-xp">{t('adjust.xp')}</label>
          <input id="renown-adjust-xp" className="FormControl" type="number" step="1" bidi={this.xp} placeholder="500 / -500" />
          <p className="helpText">{t('adjust.xp_help')}</p>
        </div>
        <div className="Form-group">
          <label for="renown-adjust-reason">{t('adjust.reason')}</label>
          <input id="renown-adjust-reason" className="FormControl" maxlength={200} bidi={this.reason} />
        </div>
        <div className="Form-group">
          <Button className="Button Button--primary" type="submit" loading={this.loading} disabled={!Number(this.xp()) || !this.reason().trim()}>
            {t('adjust.submit')}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    api<{ data: any }>('POST', `/users/${this.attrs.user.id()}/adjust`, { xp: Number(this.xp()), reason: this.reason().trim() }).then((res) => {
      this.attrs.user.pushAttributes({ renownLevel: res.data.level, renownXp: res.data.xp, renownLifetimeXp: res.data.lifetime });
      this.hide();
      app.alerts.show({ type: 'success' }, t('adjust.done'));
    }, this.loaded.bind(this));
  }
}
