(() => {
    const form = document.querySelector('#catalog-search-form');
    const input = form?.querySelector('[data-suggest-url]');
    const list = document.querySelector('#catalog-suggestions');
    if (!form || !input || !list) return;

    let debounceTimer = null;
    let activeRequest = null;

    function hideSuggestions() {
        list.hidden = true;
        list.replaceChildren();
        input.setAttribute('aria-expanded', 'false');
    }

    function selectSuggestion(suggestion) {
        input.value = suggestion.title;
        hideSuggestions();
        form.requestSubmit();
    }

    function renderSuggestions(items) {
        list.replaceChildren();
        if (!items.length) {
            hideSuggestions();
            return;
        }

        for (const item of items) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'autocomplete-option';
            button.setAttribute('role', 'option');

            const title = document.createElement('strong');
            title.textContent = item.title;
            const meta = document.createElement('small');
            meta.textContent = [item.author, item.career, item.sede].filter(Boolean).join(' · ');
            button.append(title, meta);
            button.addEventListener('click', () => selectSuggestion(item));
            list.append(button);
        }

        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    async function fetchSuggestions() {
        const query = input.value.trim();
        if (query.length < 2) {
            hideSuggestions();
            return;
        }

        activeRequest?.abort();
        activeRequest = new AbortController();
        const url = new URL(input.dataset.suggestUrl, window.location.href);
        url.searchParams.set('q', query);

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: activeRequest.signal
            });
            if (!response.ok) throw new Error('No se pudieron cargar las sugerencias.');
            const data = await response.json();
            renderSuggestions(data.suggestions || []);
        } catch (error) {
            if (error.name !== 'AbortError') hideSuggestions();
        }
    }

    input.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(fetchSuggestions, 180);
    });
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') hideSuggestions();
    });
    form.addEventListener('submit', hideSuggestions);
    document.addEventListener('click', event => {
        if (!form.contains(event.target)) hideSuggestions();
    });
})();
