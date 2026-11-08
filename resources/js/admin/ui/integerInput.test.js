import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { integerInputValue, normalizeIntegerInput } from './integerInput.js';

describe('normalizeIntegerInput', () => {
    it('allows clearing the field', () => {
        assert.equal(normalizeIntegerInput(''), '');
        assert.equal(normalizeIntegerInput(null), '');
        assert.equal(normalizeIntegerInput(undefined), '');
    });

    it('strips leading zeros so 050 becomes 50', () => {
        assert.equal(normalizeIntegerInput('050'), 50);
        assert.equal(normalizeIntegerInput('000'), 0);
        assert.equal(normalizeIntegerInput('0100'), 100);
    });

    it('keeps exact integers without padding', () => {
        assert.equal(normalizeIntegerInput('50'), 50);
        assert.equal(normalizeIntegerInput('100'), 100);
        assert.equal(normalizeIntegerInput(50), 50);
    });

    it('rejects non-numeric input', () => {
        assert.equal(normalizeIntegerInput('12a'), '');
        assert.equal(normalizeIntegerInput('1.5'), '');
    });
});

describe('integerInputValue', () => {
    it('renders a canonical string without leading zeros', () => {
        assert.equal(integerInputValue(50), '50');
        assert.equal(integerInputValue('050'), '50');
        assert.equal(integerInputValue(0), '0');
        assert.equal(integerInputValue(''), '');
        assert.equal(integerInputValue(null), '');
    });
});
