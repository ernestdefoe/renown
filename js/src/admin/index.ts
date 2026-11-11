import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import RenownPage from './RenownPage';
import registerBadges from './badges';

export const extend = [
  new Extend.Admin()
    .page(RenownPage)
    .permission(
      () => ({ permission: 'renown.adjust', icon: 'fas fa-medal', label: app.translator.trans('ernestdefoe-renown.admin.permissions.adjust') }),
      'moderate',
      50
    ),
];

app.initializers.add('ernestdefoe/renown', () => {
  registerBadges();
});
