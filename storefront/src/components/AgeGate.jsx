import { useState } from 'react';
import { t } from '../i18n.js';

export default function AgeGate({ shop }) {
    const privacy = shop?.commerce?.privacy || {};
    if (!privacy.age_gate) {
        return null;
    }
    const min = Number(privacy.age_min || 18);
    const [ok, setOk] = useState(() => {
        try {
            return window.sessionStorage.getItem('wento_age_ok') === '1';
        } catch {
            return false;
        }
    });
    if (ok) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4">
            <div className="max-w-md rounded-xl bg-white p-6 text-center shadow-lg">
                <h2 className="text-lg font-semibold">{t('storefront.age_gate.title', 'Age confirmation')}</h2>
                <p className="mt-2 text-sm text-neutral-600">
                    {t('storefront.age_gate.body', 'You must be {age} or older to enter this shop.').replace('{age}', String(min))}
                </p>
                <div className="mt-4 flex justify-center gap-2">
                    <button
                        type="button"
                        className="shop-btn shop-btn-primary"
                        onClick={() => {
                            try {
                                window.sessionStorage.setItem('wento_age_ok', '1');
                            } catch {
                                /* ignore */
                            }
                            setOk(true);
                        }}
                    >
                        {t('storefront.age_gate.confirm', 'I am {age}+').replace('{age}', String(min))}
                    </button>
                </div>
            </div>
        </div>
    );
}
