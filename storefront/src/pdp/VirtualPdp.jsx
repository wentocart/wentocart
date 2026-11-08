import SimplePdp from './SimplePdp.jsx';
import { t } from '../i18n.js';

function VirtualNote() {
    return (
        <p className="shop-pdp2-virtual-note">
            {t('storefront.pdp.virtual_note', 'This is a digital item. No shipping is charged at checkout.')}
        </p>
    );
}

export default function VirtualPdp(props) {
    return <SimplePdp {...props} belowPrice={<VirtualNote />} />;
}
