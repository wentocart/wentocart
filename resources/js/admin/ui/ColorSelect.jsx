import ColorField, { hexFromTone } from './ColorField';

export { badgeTones } from './ColorField';

export default function ColorSelect({ value, onChange, disabled }) {
    return <ColorField value={hexFromTone(value)} onChange={onChange} disabled={disabled} />;
}
