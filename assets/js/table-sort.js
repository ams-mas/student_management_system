(function () {
    'use strict';

    const persianDigits = '۰۱۲۳۴۵۶۷۸۹';
    const arabicDigits = '٠١٢٣٤٥٦٧٨٩';

    function normalize(value) {
        return String(value || '')
            .replace(/[۰-۹]/g, function (digit) { return String(persianDigits.indexOf(digit)); })
            .replace(/[٠-٩]/g, function (digit) { return String(arabicDigits.indexOf(digit)); })
            .replace(/[٬،,]/g, '')
            .replace(/\u00a0/g, ' ')
            .trim();
    }

    function cellValue(cell) {
        if (!cell) return '';
        const explicit = cell.getAttribute('data-sort-value');
        return normalize(explicit !== null ? explicit : (cell.innerText || cell.textContent || ''));
    }

    function compareValues(a, b) {
        const aClean = a.replace(/[\s$؋٪%]/g, '');
        const bClean = b.replace(/[\s$؋٪%]/g, '');
        const aNumber = aClean !== '' ? Number(aClean) : NaN;
        const bNumber = bClean !== '' ? Number(bClean) : NaN;

        if (Number.isFinite(aNumber) && Number.isFinite(bNumber)) {
            return aNumber - bNumber;
        }

        const aDate = Date.parse(a);
        const bDate = Date.parse(b);
        if (Number.isFinite(aDate) && Number.isFinite(bDate)) {
            return aDate - bDate;
        }

        return a.localeCompare(b, 'fa', { numeric: true, sensitivity: 'base' });
    }

    function installTableSorting(table) {
        if (table.dataset.sortableInitialized === 'true') return;
        const head = table.tHead;
        const body = table.tBodies && table.tBodies[0];
        if (!head || !head.rows.length || !body) return;

        const headers = Array.from(head.rows[head.rows.length - 1].cells);
        if (!headers.length) return;
        table.dataset.sortableInitialized = 'true';
        table.classList.add('table-sortable');

        headers.forEach(function (header, columnIndex) {
            if (header.hasAttribute('data-no-sort') || header.colSpan > 1) return;
            header.tabIndex = 0;
            header.setAttribute('role', 'button');
            header.setAttribute('aria-label', (header.innerText || header.textContent || 'ستون') + '؛ برای مرتب‌سازی کلیک کنید');
            header.setAttribute('aria-sort', 'none');
            header.classList.add('table-sort-header');

            function sortColumn() {
                const rows = Array.from(body.rows);
                if (rows.length < 2 || rows.some(function (row) { return row.cells.length !== headers.length; })) return;

                const current = header.dataset.sortDirection || 'none';
                const direction = current === 'asc' ? 'desc' : 'asc';
                headers.forEach(function (other) {
                    delete other.dataset.sortDirection;
                    other.setAttribute('aria-sort', 'none');
                });

                const indexedRows = rows.map(function (row, index) {
                    return { row: row, index: index, value: cellValue(row.cells[columnIndex]) };
                });
                indexedRows.sort(function (a, b) {
                    const result = compareValues(a.value, b.value);
                    return (direction === 'asc' ? result : -result) || (a.index - b.index);
                });
                const fragment = document.createDocumentFragment();
                indexedRows.forEach(function (entry) { fragment.appendChild(entry.row); });
                body.appendChild(fragment);

                header.dataset.sortDirection = direction;
                header.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');
            }

            header.addEventListener('click', sortColumn);
            header.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    sortColumn();
                }
            });
        });
    }

    function initialize() {
        document.querySelectorAll('table').forEach(installTableSorting);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
})();
