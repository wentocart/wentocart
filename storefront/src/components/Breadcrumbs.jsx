import { Link } from 'react-router-dom';

export default function Breadcrumbs({ items = [] }) {
    if (!items.length) {
        return null;
    }
    return (
        <nav aria-label="Breadcrumb" className="text-sm text-neutral-500">
            <ol className="flex flex-wrap items-center gap-1">
                {items.map((item, index) => (
                    <li key={`${item.url}-${index}`} className="flex items-center gap-1">
                        {index > 0 ? <span>/</span> : null}
                        {item.url ? <Link to={item.url} className="hover:text-neutral-900">{item.name}</Link> : <span className="text-neutral-800">{item.name}</span>}
                    </li>
                ))}
            </ol>
        </nav>
    );
}
