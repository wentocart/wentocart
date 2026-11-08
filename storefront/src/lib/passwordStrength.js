export function passwordChecks(value) {
    const v = String(value || '');

    return {
        length: v.length >= 8 && v.length <= 72,
        capital: /[A-Z]/.test(v),
        number: /\d/.test(v),
        spaces: v.length > 0 && !/\s/.test(v),
    };
}

export function passwordScore(value) {
    return Object.values(passwordChecks(value)).filter(Boolean).length;
}

export function passwordReady(value) {
    return passwordScore(value) === 4;
}

export function passwordTone(score) {
    if (score <= 1) {
        return 'weak';
    }
    if (score === 2) {
        return 'fair';
    }
    if (score === 3) {
        return 'medium';
    }

    return 'strong';
}
