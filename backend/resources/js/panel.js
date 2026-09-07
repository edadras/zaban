/*
 * The panel's small amount of page furniture.
 *
 * Everything with real behaviour - the live classroom - is in room.js. This
 * file is deliberately the boring one: a drawer, a confirmation, a form that
 * submits when a select changes.
 */

const sidebar = document.getElementById('panel-sidebar');
const backdrop = document.querySelector('[data-sidebar-backdrop]');

function closeSidebar() {
    sidebar?.classList.add('hidden');
    backdrop?.classList.add('hidden');
}

document.querySelector('[data-toggle-sidebar]')?.addEventListener('click', () => {
    const opening = sidebar?.classList.contains('hidden');
    sidebar?.classList.toggle('hidden', !opening);
    backdrop?.classList.toggle('hidden', !opening);
});

backdrop?.addEventListener('click', closeSidebar);

/* A destructive button says what it is about to destroy. */
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

/* Filters that apply themselves. */
document.querySelectorAll('[data-submit-on-change]').forEach((el) => {
    el.addEventListener('change', () => el.form?.submit());
});

/*
 * Reordering the coach's shelf. The list is small enough that arrows beat a
 * drag handle: they work on a phone, with a keyboard, and with a screen reader.
 */
document.querySelectorAll('[data-reorder]').forEach((list) => {
    const endpoint = list.dataset.reorder;

    list.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-move]');
        if (!button) return;

        const item = button.closest('[data-material-id]');
        const sibling = button.dataset.move === 'up'
            ? item.previousElementSibling
            : item.nextElementSibling;

        if (!sibling) return;

        button.dataset.move === 'up'
            ? list.insertBefore(item, sibling)
            : list.insertBefore(sibling, item);

        const order = [...list.querySelectorAll('[data-material-id]')]
            .map((row) => Number(row.dataset.materialId));

        await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ order }),
        });
    });
});
