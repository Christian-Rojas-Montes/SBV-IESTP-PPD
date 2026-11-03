(() => {
    const dialog = document.querySelector('[data-reservation-modal]');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    const pickupInput = dialog.querySelector('[data-pickup-date]');
    const dueInput = dialog.querySelector('[data-due-date]');
    const bookIdInput = dialog.querySelector('[data-reservation-book-id]');
    const bookTitle = dialog.querySelector('[data-reservation-book-title]');
    const returnInput = dialog.querySelector('[name="return_to"]');

    function addDays(value, days) {
        const [year, month, day] = value.split('-').map(Number);
        return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10);
    }

    function updateDueDateRange(resetValue = false) {
        if (!pickupInput.value) {
            dueInput.min = '';
            dueInput.max = '';
            return;
        }

        dueInput.min = addDays(pickupInput.value, 1);
        dueInput.max = addDays(pickupInput.value, 21);
        if (resetValue || !dueInput.value || dueInput.value < dueInput.min || dueInput.value > dueInput.max) {
            dueInput.value = dueInput.min;
        }
    }

    document.querySelectorAll('[data-reserve-open]').forEach(button => {
        button.addEventListener('click', () => {
            pickupInput.min = dialog.dataset.pickupMin;
            pickupInput.max = dialog.dataset.pickupMax;
            pickupInput.value = pickupInput.min;
            updateDueDateRange(true);
            bookIdInput.value = button.dataset.bookId;
            bookTitle.textContent = button.dataset.bookTitle || '';
            if (returnInput) returnInput.value = dialog.dataset.returnTo || 'catalog.php';
            dialog.showModal();
        });
    });

    pickupInput.addEventListener('change', () => updateDueDateRange(true));
    dialog.querySelectorAll('[data-reservation-close]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
})();
