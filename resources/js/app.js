// Nugget JS entrypoint.

// The Minton template shell: sidebar, top bar and the light/dark switch. jQuery is used only in there.
import './minton/app';
import './minton/layout';

// htmx swaps part of a page from a link or a form: the transaction table's sorting, paging and filters.
import htmx from 'htmx.org';

import './asset-form';
import './transaction-form';
import './parcel-picker';

window.htmx = htmx;
