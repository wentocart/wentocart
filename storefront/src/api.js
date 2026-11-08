import axios from 'axios';
import { shopSession } from './state/shopSession.js';
import { webPath } from './webPath.js';

axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';

const api = axios.create({ baseURL: webPath('/api/v1') });

api.interceptors.request.use((config) => {
    const { currency, locale } = shopSession();
    const headers = axios.AxiosHeaders.from(config.headers || {});
    if (currency) {
        headers.set('X-Wento-Currency', currency);
    }
    if (locale) {
        headers.set('X-Wento-Locale', locale);
    }
    config.headers = headers;
    if (!currency) {
        return config;
    }
    const params = config.params instanceof URLSearchParams
        ? Object.fromEntries(config.params.entries())
        : { ...(config.params || {}) };
    if (!params.currency && !params.currency_code) {
        params.currency = currency;
    }
    config.params = params;

    return config;
});

export async function csrf() {
    await axios.get(webPath('/sanctum/csrf-cookie'));
}

export default api;
