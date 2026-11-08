import { Children, cloneElement, isValidElement } from 'react';

const paths = {
    search: <path d="M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16Zm10 2-5-5" strokeLinecap="round" />,
    heart: <path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2 4 4 0 0 1 7 2c0 5.6-7 10-7 10Z" strokeLinejoin="round" />,
    phone: <path d="M7 3.5h3.2l1.2 3.2-2.1 1.2a11 11 0 0 0 5.8 5.8l1.2-2.1 3.2 1.2V16c0 .9-.8 1.8-1.8 1.8C9.2 17.8 6.2 14.8 6.2 7.3 6.2 6.3 7 3.5 7 3.5Z" strokeLinejoin="round" />,
    mail: <><rect x="3.5" y="5" width="17" height="14" rx="2" /><path d="m4 7.5 8 5.5 8-5.5" strokeLinecap="round" /></>,
    send: <path d="m3.5 11.5 17-8-6.5 17-2.8-6.9-7.7-2.1Zm7.7 2.1 4.7-4.7" strokeLinecap="round" strokeLinejoin="round" />,
    chevronDown: <path d="m6 9 6 6 6-6" strokeLinecap="round" strokeLinejoin="round" />,
    compare: <path d="M4 12a8 8 0 0 1 13.2-6.1L20 8M20 4v4h-4M20 12a8 8 0 0 1-13.2 6.1L4 16M4 20v-4h4" strokeLinecap="round" strokeLinejoin="round" />,
    cart: <><circle cx="9" cy="20" r="1.2" fill="currentColor" stroke="none" /><circle cx="17" cy="20" r="1.2" fill="currentColor" stroke="none" /><path d="M3 4h2l2.2 11h11.3l1.8-7H7" strokeLinecap="round" strokeLinejoin="round" /></>,
    user: <><circle cx="12" cy="8" r="3.2" /><path d="M5 19c1.4-3 4-4.5 7-4.5s5.6 1.5 7 4.5" strokeLinecap="round" /></>,
    'user-circle': <><circle cx="12" cy="12" r="9" /><circle cx="12" cy="10" r="2.6" /><path d="M7.5 17.2c.9-2.2 2.7-3.2 4.5-3.2s3.6 1 4.5 3.2" strokeLinecap="round" /></>,
    chevron: <path d="m9 6 6 6-6 6" strokeLinecap="round" />,
    menu: <path d="M4 7h16M4 12h16M4 17h16" strokeLinecap="round" />,
    truck: <path d="M3 7h11v8H3zM14 10h4l3 3v2h-7M6 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm10 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" />,
    return: <path d="M4 12h12a4 4 0 1 0-4-4M4 12l3-3M4 12l3 3" strokeLinecap="round" />,
    shield: <path d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6l-7-3Z" />,
    star: <path d="m12 3 2.4 5.4 5.8.6-4.4 3.9 1.3 5.7L12 16.8 6.9 18.6 8.2 13 3.8 9l5.8-.6L12 3Z" fill="currentColor" stroke="none" />,
    starOutline: <path d="m12 3 2.4 5.4 5.8.6-4.4 3.9 1.3 5.7L12 16.8 6.9 18.6 8.2 13 3.8 9l5.8-.6L12 3Z" strokeLinejoin="round" />,
    close: <path d="M6 6l12 12M18 6 6 18" strokeLinecap="round" />,
    filter: <path d="M4 6h16M7 12h10M10 18h4" strokeLinecap="round" />,
    grid: <path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" />,
    list: <path d="M4 6h16M4 12h16M4 18h16" strokeLinecap="round" />,
    check: <path d="m5 12 5 5 9-10" strokeLinecap="round" strokeLinejoin="round" />,
    thumbsUp: <path d="M7 11v9H4.8A1.8 1.8 0 0 1 3 18.2V12.8A1.8 1.8 0 0 1 4.8 11H7Zm0 0 3.1-6.2A2.2 2.2 0 0 1 12.1 3.6c.9 0 1.6.8 1.5 1.7L13.2 9H19a2 2 0 0 1 2 2.3l-1 6.2A3 3 0 0 1 17 20H7" strokeLinejoin="round" />,
    thumbsDown: <path d="M7 13V4H4.8A1.8 1.8 0 0 0 3 5.8v5.4A1.8 1.8 0 0 0 4.8 13H7Zm0 0 3.1 6.2a2.2 2.2 0 0 0 2 1.2c.9 0 1.6-.8 1.5-1.7L13.2 15H19a2 2 0 0 0 2-2.3l-1-6.2A3 3 0 0 0 17 4H7" strokeLinejoin="round" />,
    flame: <path d="M12 3c2 4-1 5 1 9 3-2 5-5 5-8 3 4 4 7 4 10a8 8 0 1 1-16 0c0-4 3-8 6-11Z" />,
    bell: <path d="M6 9a6 6 0 1 1 12 0c0 3.2 1.1 4.6 1.8 5.5.3.4 0 1-.5 1H4.7c-.5 0-.8-.6-.5-1C5 13.6 6 12.2 6 9Zm4.2 9.2a2 2 0 0 0 3.6 0" strokeLinecap="round" strokeLinejoin="round" />,
    bolt: <path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z" strokeLinejoin="round" />,
    pin: <path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Zm0-9a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" />,
    zoom: <><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5M8 11h6M11 8v6" strokeLinecap="round" /></>,
    zoomOut: <><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5M8 11h6" strokeLinecap="round" /></>,
    ar: <path d="M12 3 4 7v10l8 4 8-4V7l-8-4Zm0 4v14M4 7l8 4 8-4" />,
    plus: <path d="M12 5v14M5 12h14" strokeLinecap="round" />,
    settings: <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1A2 2 0 1 1 2.3 17l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H1a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1A2 2 0 1 1 5.1 3.3l.1.1a1.7 1.7 0 0 0 1.8.3H7a1.7 1.7 0 0 0 1-1.5V2a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V7c.3.7 1 1.2 1.7 1.3H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" strokeLinejoin="round" />,
    download: <path d="M12 3v12M7 10l5 5 5-5M5 21h14" strokeLinecap="round" strokeLinejoin="round" />,
    file: <><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" strokeLinecap="round" /></>,
    upload: <path d="M12 21V9M7 14l5-5 5 5M5 3h14" strokeLinecap="round" strokeLinejoin="round" />,
    refresh: <path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" strokeLinecap="round" strokeLinejoin="round" />,
    pause: <><rect x="6.5" y="5" width="3.5" height="14" rx="0.8" /><rect x="14" y="5" width="3.5" height="14" rx="0.8" /></>,
    play: <path d="M8 5.5v13l11-6.5-11-6.5Z" strokeLinejoin="round" />,
    skipForward: <path d="M5 6v12l8.5-6L5 6Zm9.5 0v12M18.5 6v12" strokeLinecap="round" strokeLinejoin="round" />,
    'chevron-left': <path d="M15 18l-6-6 6-6" strokeLinecap="round" strokeLinejoin="round" />,
    'chevron-right': <path d="M9 18l6-6-6-6" strokeLinecap="round" strokeLinejoin="round" />,
    'chevron-up': <path d="M18 15l-6-6-6 6" strokeLinecap="round" strokeLinejoin="round" />,
    'chevron-down': <path d="M6 9l6 6 6-6" strokeLinecap="round" strokeLinejoin="round" />,
    trash: <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" strokeLinecap="round" strokeLinejoin="round" />,
    edit: <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z" strokeLinecap="round" strokeLinejoin="round" />,
    layers: <path d="M12 2 2 7l10 5 10-5-10-5Zm0 7L2 14l10 5 10-5-10-5Zm0 7L2 21l10 5 10-5-10-5" strokeLinejoin="round" />,
    package: <path d="M12 3 3 7.5v9L12 21l9-4.5v-9L12 3Zm0 0v18M3 7.5l9 4.5 9-4.5" strokeLinejoin="round" />,
    store: <><path d="M4 10V8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2M4 10h16v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-9Z" strokeLinejoin="round" /><path d="M9 21v-5h6v5M10 10h4" strokeLinecap="round" /></>,
    facebook: <path d="M14 8h3V5h-3c-2.2 0-4 1.8-4 4v2H8v3h2v6h3v-6h2.2L16 11h-3V9c0-.6.4-1 1-1Z" fill="currentColor" stroke="none" />,
    twitter: <path d="M4 18c5.5 4 12.5 2 15-6 .6 0 2.2-.4 3-1-.4.1-1.6.3-2.4 0 .8-.1 1.8-.8 2.1-1.6-.7.4-1.8.7-2.6.5C18 8.6 16.4 8 14.8 8c-2.4 0-3.8 2.1-3.3 4.3C8.3 12.2 5.4 10 4 7.4 3.2 9.2 4 12 6.2 13.2 5.5 13.2 4.8 13 4.2 12.6c0 1.8 1.3 3.3 3.1 3.7-.6.2-1.3.2-2 .1.7 1.5 2.3 2.5 4.1 2.5" strokeLinecap="round" />,
    x: <path d="M5 5l14 14M19 5 5 19" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" />,
    instagram: <><rect x="4" y="4" width="16" height="16" rx="5" /><circle cx="12" cy="12" r="3.5" /><circle cx="17.2" cy="6.8" r="0.8" fill="currentColor" stroke="none" /></>,
    youtube: <><path d="M4.5 8.5c.3-1.4 1.4-2.4 2.8-2.6C9.5 5.5 12 5.5 12 5.5s2.5 0 4.7.4c1.4.2 2.5 1.2 2.8 2.6.3 1.3.5 2.7.5 3.5s-.2 2.2-.5 3.5c-.3 1.4-1.4 2.4-2.8 2.6-2.2.4-4.7.4-4.7.4s-2.5 0-4.7-.4c-1.4-.2-2.5-1.2-2.8-2.6C4.2 14.2 4 12.8 4 12s.2-2.2.5-3.5Z" /><path d="m10.5 9.5 5 2.5-5 2.5v-5Z" fill="currentColor" stroke="none" /></>,
    lock: <><rect x="5" y="10" width="14" height="10" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" strokeLinecap="round" /></>,
    card: <><rect x="3" y="6" width="18" height="12" rx="2" /><path d="M3 10h18M7 15h3" strokeLinecap="round" /></>,
    eye: <><path d="M4 12s3.2-5.5 8-5.5 8 5.5 8 5.5-3.2 5.5-8 5.5S4 12 4 12Z" strokeLinejoin="round" /><circle cx="12" cy="12" r="2.35" /></>,
    eyeOff: <><path d="M5 5l14 14M8.4 8.7C6.5 9.9 5.1 12 5.1 12s3.1 5.2 6.9 5.2c1.2 0 2.3-.3 3.3-.8M15.4 8.4C14.4 7.7 13.3 6.8 12 6.8 8.2 6.8 5.1 12 5.1 12M10 10.2a2.35 2.35 0 0 0 3.4 3.2" strokeLinecap="round" strokeLinejoin="round" /></>,
    calendar: <><rect x="4" y="5" width="16" height="15" rx="2" /><path d="M8 3v4M16 3v4M4 10h16" strokeLinecap="round" /></>,
    tag: <path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9-7-7Z" strokeLinejoin="round" />,
    arrowLeft: <path d="M19 12H5M11 6l-6 6 6 6" strokeLinecap="round" strokeLinejoin="round" />,
    trash: <path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13h10l1-13M10 11v6M14 11v6" strokeLinecap="round" strokeLinejoin="round" />,
    bag: <><path d="M6 8h12l-1 12H7L6 8Z" strokeLinejoin="round" /><path d="M9 8V7a3 3 0 0 1 6 0v1" strokeLinecap="round" /></>,
    package: <><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" strokeLinejoin="round" /><path d="M3.3 7 12 12l8.7-5M12 22V12" strokeLinecap="round" strokeLinejoin="round" /></>,
    gift: <><rect x="4" y="10" width="16" height="10" rx="1.5" /><path d="M12 10v10M4 14h16M12 10c-2.2 0-4-1.3-4-3s2-2.2 4-1c2-1.2 4-.2 4 1s-1.8 3-4 3Z" strokeLinecap="round" strokeLinejoin="round" /></>,
    help: <><circle cx="12" cy="12" r="9" /><path d="M9.6 9.2a2.4 2.4 0 1 1 3.5 2.1c-.8.5-1.3 1-1.3 1.9M12 17h.01" strokeLinecap="round" /></>,
    checkCircle: <><circle cx="12" cy="12" r="9" /><path d="m9 12 2 2 4-4" strokeLinecap="round" strokeLinejoin="round" /></>,
    alertCircle: <><circle cx="12" cy="12" r="9" /><path d="M12 8v4M12 16h.01" strokeLinecap="round" strokeLinejoin="round" /></>,
    alertTriangle: <><path d="M12 3 2 21h20L12 3Z" /><path d="M12 9v4M12 17h.01" strokeLinecap="round" strokeLinejoin="round" /></>,
    info: <><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16h.01" strokeLinecap="round" strokeLinejoin="round" /></>,
    logout: <path d="M10 5H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h4M15 16l4-4-4-4M9 12h10" strokeLinecap="round" strokeLinejoin="round" />,
    store: <path d="M4 10 6 5h12l2 5v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-9Zm0 0h16M9 20v-6h6v6" strokeLinecap="round" strokeLinejoin="round" />,
    home: <path d="M4 11.5 12 4l8 7.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-8.5Z" strokeLinejoin="round" />,
    briefcase: <><rect x="3" y="8" width="18" height="12" rx="2" /><path d="M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18" strokeLinecap="round" /></>,
    building: <><path d="M4 20V6a2 2 0 0 1 2-2h6v16M12 20h8V9a1 1 0 0 0-1-1h-7" /><path d="M7 8h2M7 12h2M7 16h2M15 12h2M15 16h2" strokeLinecap="round" /></>,
    pencil: <path d="m14 5 5 5M4 20l1.5-5.5L16 4l4 4L9.5 18.5 4 20Z" strokeLinejoin="round" />,
    google: <path d="M12 11.2v2.6h4.2c-.2 1.2-1.5 3.5-4.2 3.5A4.8 4.8 0 1 1 12 7.2c1.3 0 2.2.5 2.7 1l1.8-1.8A7.3 7.3 0 0 0 12 4.5 7.5 7.5 0 1 0 12 19.5c4.3 0 7.2-3 7.2-7.3 0-.5 0-.9-.1-1.3H12Z" fill="currentColor" stroke="none" />,
    apple: <path d="M16.4 12.7c0-2.2 1.8-3.3 1.9-3.4-1-1.5-2.7-1.7-3.2-1.7-1.4-.1-2.7.8-3.4.8-.7 0-1.8-.8-3-.8-1.5 0-3 .9-3.8 2.3-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.3 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7 2-1.1 2.8-2.2c.9-1.3 1.2-2.6 1.3-2.6-.1 0-2.4-.9-2.4-3.7Zm-2.2-6.5c.6-.8 1.1-1.9.9-3-.9 0-2 .6-2.6 1.3-.6.7-1.1 1.8-.9 2.9 1 .1 2-.5 2.6-1.2Z" fill="currentColor" stroke="none" />,
};

const ICON_ALIASES = {
    'arrow-left': 'arrowLeft',
    'eye-off': 'eyeOff',
    'star-outline': 'starOutline',
    chevrondown: 'chevron-down',
};

export const iconNames = Object.keys(paths);

export function kitIcon(name, fallback = 'grid') {
    if (name && paths[name]) {
        return name;
    }
    const mapped = ICON_ALIASES[name];
    if (mapped && paths[mapped]) {
        return mapped;
    }

    return fallback;
}

export function Icon({ name, className = 'h-5 w-5', ...props }) {
    const d = paths[kitIcon(name, '')] || paths[name];
    const nodes = d == null
        ? null
        : Children.map(d, (child, index) => (
            isValidElement(child) ? cloneElement(child, { key: child.key ?? index }) : child
        ));

    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" overflow="visible" className={className} aria-hidden="true" {...props}>
            {nodes}
        </svg>
    );
}

export function BadgeCount({ count }) {
    if (!count) {
        return null;
    }
    return (
        <span className="absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full bg-[var(--shop-sale,#ff4d4d)] px-1 text-[10px] font-bold text-white">
            {count > 99 ? '99+' : count}
        </span>
    );
}
