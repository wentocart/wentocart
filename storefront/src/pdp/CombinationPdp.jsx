import SimplePdp from './SimplePdp.jsx';

export default function CombinationPdp({ product, actionSlots, ...rest }) {
    return <SimplePdp product={product} actionSlots={actionSlots} {...rest} />;
}
