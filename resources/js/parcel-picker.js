// The parcel picker: as units are typed, show the gain on each parcel and the
// totals. Units are whole numbers of 10^-8 and money is whole cents, never
// floats. The server works the saved figures out itself.
const picker = document.querySelector('[data-parcel-picker]');

if (picker) {
    const scaled = (text, places) => {
        const match = new RegExp(`^(\\d+)(?:\\.(\\d{0,${places}}))?$`).exec(text.trim());

        return match ? BigInt(match[1] + (match[2] ?? '').padEnd(places, '0')) : null;
    };

    // a × b ÷ c, a half rounded up. All three are zero or more.
    const share = (a, b, c) => (2n * a * b + c) / (2n * c);

    const money = (cents) => {
        const digits = (cents < 0n ? -cents : cents).toString().padStart(3, '0');
        const dollars = digits.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        return `${cents < 0n ? '-' : ''}$${dollars}.${digits.slice(-2)}`;
    };

    const units = (amount) => {
        const digits = amount.toString().padStart(9, '0');
        const fraction = digits.slice(-8).replace(/0+$/, '');

        return digits.slice(0, -8) + (fraction ? `.${fraction}` : '');
    };

    const saleUnits = scaled(picker.dataset.saleUnits, 8);
    const saleTotal = scaled(picker.dataset.saleTotal, 2);

    const update = () => {
        let picked = 0n;
        let gain = 0n;

        picker.querySelectorAll('[data-choice]').forEach((row) => {
            const typed = row.querySelector('input').value;
            const used = typed.trim() === '' ? 0n : scaled(typed, 8);
            const cell = row.querySelector('[data-gain]');

            if (used === null) {
                cell.textContent = '';

                return;
            }

            const onThis = share(saleTotal, used, saleUnits) - share(scaled(row.dataset.cost, 2), used, scaled(row.dataset.units, 8));

            cell.textContent = used > 0n ? money(onThis) : '';
            picked += used;
            gain += onThis;
        });

        const total = picker.querySelector('[data-picked]');
        total.textContent = units(picked);
        total.classList.toggle('text-danger', picked !== saleUnits);
        picker.querySelector('[data-total-gain]').textContent = money(gain);
    };

    picker.addEventListener('input', update);
    update();
}
