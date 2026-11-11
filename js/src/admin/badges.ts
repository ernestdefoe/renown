import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';

declare const flarum: any;

/**
 * Renown's metrics in FoF Badges' trigger builder. Its list of metrics is
 * fixed in its own code, so they're added here once its builder loads; the
 * server registers the metrics (src/Badges). Without fof/badges none of this runs.
 */
const METRICS = ['renown_level', 'renown_prestige', 'renown_lifetime_xp'];

export default function registerBadges() {
  flarum.reg.onLoad('fof-badges', 'admin/components/TriggerBuilder', (Builder: any) => {
    const proto = Builder?.prototype;
    if (!proto || typeof proto.getAvailableMetrics !== 'function') return;
    extend(proto, 'getAvailableMetrics', function (metrics: { value: string; label: string }[]) {
      if (!Array.isArray(metrics)) return;
      metrics.push(...METRICS.map((value) => ({ value, label: String(app.translator.trans('ernestdefoe-renown.admin.badges.' + value, {}, true)) })));
    });
  });
}
