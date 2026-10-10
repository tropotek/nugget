// The transaction form: show the fields the chosen type uses, and show the
// total the server will work out as the total field's placeholder. Amounts
// are whole numbers of 10^-8 here, never floats.
const form = document.querySelector('[data-transaction-form]');

if (form) {
    const type = form.querySelector('[name="type"]');
    const field = (name) => form.querySelector(`[name="${name}"]`);
    const uses = (group) => group.dataset.forTypes.split(' ').includes(type.value);

    const parse = (text) => {
        const match = /^(-?)(\d+)(?:\.(\d{0,8}))?$/.exec(text.trim());

        if (!match) {
            return null;
        }

        const amount = BigInt(match[2] + (match[3] ?? '').padEnd(8, '0'));

        return match[1] ? -amount : amount;
    };

    // A whole number of 10^-16 as dollars and cents, a half rounded away from zero.
    const aud = (amount) => {
        const cents = ((amount < 0n ? -amount : amount) + 5n * 10n ** 13n) / 10n ** 14n;
        const digits = cents.toString().padStart(3, '0');

        return `${amount < 0n && cents > 0n ? '-' : ''}${digits.slice(0, -2)}.${digits.slice(-2)}`;
    };

    const amountOf = (name) => {
        const input = field(name);

        return uses(input.closest('[data-for-types]')) ? parse(input.value || '0') : 0n;
    };

    const total = () => {
        const units = parse(field('units').value);
        const price = amountOf('unit_price');
        const fee = amountOf('fee');

        if (units === null || price === null || fee === null) {
            return '';
        }

        const sign = { buy: 1n, sell: -1n }[type.value] ?? 0n;

        return aud(units * price + sign * fee * 10n ** 8n);
    };

    const update = () => {
        form.querySelectorAll('[data-for-types]').forEach((group) => {
            group.hidden = !uses(group);
        });

        field('total').placeholder = total();
    };

    form.addEventListener('input', update);
    update();
}
