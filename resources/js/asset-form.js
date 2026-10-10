// The asset form: show only the fields of the chosen price source.
const select = document.querySelector('[data-priced-by-select]');

if (select) {
    const show = () => document.querySelectorAll('[data-priced-by]').forEach((group) => {
        group.hidden = group.dataset.pricedBy !== select.value;
    });

    select.addEventListener('change', show);
    show();
}
