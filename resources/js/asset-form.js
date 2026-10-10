// The asset form. Show only the fields of the chosen price source.
const select = document.querySelector('[data-priced-by-select]');

if (select) {
    const show = () => document.querySelectorAll('[data-priced-by]').forEach((group) => {
        group.hidden = group.dataset.pricedBy !== select.value;
    });

    select.addEventListener('change', show);
    show();
}

// Offer the markets of the chosen exchange as the market is typed.
const exchange = document.querySelector('[data-exchange-select]');
const market = document.querySelector('[data-market-input]');

if (exchange && market) {
    const offer = () => market.setAttribute('list', exchange.selectedOptions[0]?.dataset.markets ?? '');

    exchange.addEventListener('change', offer);
    offer();
}
