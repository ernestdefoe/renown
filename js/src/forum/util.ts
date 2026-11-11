import app from 'flarum/forum/app';

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-renown.forum.${key}`, params);
export const tx = (key: string, params: Record<string, any> = {}): string =>
  String(app.translator.trans(`ernestdefoe-renown.forum.${key}`, params, true));

/** Renown's own endpoints; a refusal shows as one plain sentence (once: the request's own alert is replaced). */
export const api = <T = any>(method: string, path: string, body?: any): Promise<T> =>
  app.request<T>({
    method,
    url: app.forum.attribute('apiUrl') + '/renown' + path,
    body,
    errorHandler: (error: any) => {
      const code = error?.response?.errors?.[0]?.code;
      const key = `ernestdefoe-renown.forum.errors.${code}`;
      const known = code && app.translator.trans(key, {}, true) !== key;
      app.alerts.show({ type: 'error' }, app.translator.trans(known ? key : 'core.lib.error.generic_message'));
      throw error;
    },
  });

export const number = (n: number): string => (n || 0).toLocaleString();
