const tileColors = {
    facebook: '#1877f2',
    instagram: '#e1306c',
    youtube: '#ff0000',
    twitter: '#000',
    linkedin: '#0a66c2',
    tiktok: '#010101',
};

function SocialTile({ brand, x, y, size, viewBox = '0 0 24 24', children }) {
    return (
        <svg viewBox="0 0 36 36" width="36" height="36" aria-hidden="true" focusable="false" className="shop-public-hero__social-glyph">
            <rect width="36" height="36" rx="9" fill={tileColors[brand] || '#6b7280'} />
            <svg x={x} y={y} width={size} height={size} viewBox={viewBox}>
                {children}
            </svg>
        </svg>
    );
}

const tiles = {
    facebook: (
        <SocialTile brand="facebook" x={7} y={7} size={22}>
            <path
                fill="#fff"
                d="M14 13.5h2.5l1-4H14v-2c0-1.03 0-2 2-2h1.5V2.14c-.326-.043-1.557-.14-2.857-.14C11.928 2 10 3.657 10 6.7v2.8H7v4h3V22h4z"
            />
        </SocialTile>
    ),
    instagram: (
        <SocialTile brand="instagram" x={6} y={6} size={24}>
            <g fill="#fff">
                <path
                    fillRule="evenodd"
                    d="M12 7a5 5 0 1 0 0 10a5 5 0 0 0 0-10m-3 5a3 3 0 1 0 6 0a3 3 0 0 0-6 0"
                    clipRule="evenodd"
                />
                <path d="M18 5a1 1 0 1 0 0 2a1 1 0 0 0 0-2" />
                <path
                    fillRule="evenodd"
                    d="M5 1a4 4 0 0 0-4 4v14a4 4 0 0 0 4 4h14a4 4 0 0 0 4-4V5a4 4 0 0 0-4-4zm14 2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2"
                    clipRule="evenodd"
                />
            </g>
        </SocialTile>
    ),
    youtube: (
        <SocialTile brand="youtube" x={6} y={6} size={24} viewBox="56 22 145 128">
            <path fill="#fff" d="m102.421 128.06l66.328-38.418l-66.328-38.418z" />
        </SocialTile>
    ),
    twitter: (
        <SocialTile brand="twitter" x={6} y={6} size={24}>
            <path
                fill="none"
                stroke="#fff"
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="1.7"
                d="m13.081 10.712l-4.786-6.71a.6.6 0 0 0-.489-.252H5.28a.6.6 0 0 0-.488.948l6.127 8.59m2.162-2.576l6.127 8.59a.6.6 0 0 1-.488.948h-2.526a.6.6 0 0 1-.489-.252l-4.786-6.71m2.162-2.576l5.842-6.962m-8.004 9.538L5.077 20.25"
            />
        </SocialTile>
    ),
    linkedin: (
        <SocialTile brand="linkedin" x={7} y={7} size={22}>
            <path
                fill="#fff"
                d="M4.98 3.5a2.5 2.5 0 1 1 0 5a2.5 2.5 0 0 1 0-5M3 9h4v12H3zM9 9h3.8v1.7h.05c.53-1 1.83-2.05 3.77-2.05C20.2 8.65 21 10.9 21 14v7h-4v-6.2c0-1.48-.03-3.38-2.06-3.38-2.06 0-2.37 1.6-2.37 3.27V21H9z"
            />
        </SocialTile>
    ),
    tiktok: (
        <SocialTile brand="tiktok" x={6} y={6} size={24}>
            <path
                fill="#fff"
                d="M16.6 5.82A4.28 4.28 0 0 1 15.54 3h-3.09v12.4a2.59 2.59 0 1 1-1.84-2.48V9.77a5.67 5.67 0 1 0 4.93 5.62V8.87a7.35 7.35 0 0 0 4.3 1.38V7.16a4.25 4.25 0 0 1-3.24-1.34"
            />
        </SocialTile>
    ),
};

export function SocialBrandIcon({ brand }) {
    return tiles[brand] || null;
}
