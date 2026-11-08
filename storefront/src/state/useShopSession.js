import { useEffect, useState } from 'react';
import { shopSession, subscribeShopSession } from './shopSession.js';

export function useShopSession() {
    const [session, setSession] = useState(shopSession);

    useEffect(() => subscribeShopSession(setSession), []);

    return session;
}
