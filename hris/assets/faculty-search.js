(() => {
    const form = document.querySelector('[data-live-faculty-search]');
    if (!form) return;

    const input = form.elements.namedItem('q');
    const results = document.getElementById('directory-results');
    const announcement = document.querySelector('[data-faculty-search-announcement]');
    const error = document.querySelector('[data-faculty-search-error]');
    let typingTimer;
    let pending;

    function cancelSearch() {
        clearTimeout(typingTimer);
        pending?.abort();
    }

    async function refreshResults() {
        cancelSearch();
        const request = new AbortController();
        pending = request;
        const pageUrl = new URL(form.action);
        const params = new URLSearchParams(new FormData(form));
        const query = input.value.trim();
        if (query) params.set('q', query);
        else params.delete('q');
        pageUrl.search = params.toString();
        const requestUrl = new URL(pageUrl);
        requestUrl.searchParams.set('partial', 'results');

        error.hidden = true;
        results.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(requestUrl, { signal: request.signal });
            if (request.signal.aborted) return;
            if (response.redirected && new URL(response.url).origin === window.location.origin) {
                window.location.assign(response.url);
                return;
            }
            if (!response.ok) throw new Error('Faculty search failed.');

            const template = document.createElement('template');
            template.innerHTML = await response.text();
            if (request.signal.aborted) return;
            const next = template.content.querySelector('#directory-results');
            if (!next) throw new Error('Missing faculty results.');

            results.replaceChildren(...next.childNodes);
            results.dataset.resultCount = next.dataset.resultCount;
            const count = Number(next.dataset.resultCount);
            announcement.textContent = count === 0
                ? 'No matching faculty records.'
                : 'Found ' + count.toLocaleString() + (count === 1 ? ' faculty record.' : ' faculty records.');
            window.history.replaceState(window.history.state, '', pageUrl);
        } catch (failure) {
            if (!request.signal.aborted) error.hidden = false;
        } finally {
            if (pending === request) {
                pending = null;
                results.removeAttribute('aria-busy');
            }
        }
    }

    function queueSearch(event) {
        cancelSearch();
        if (!event.isComposing) typingTimer = setTimeout(refreshResults, 200);
    }

    input.addEventListener('input', queueSearch);
    input.addEventListener('compositionend', queueSearch);
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        refreshResults();
    });
})();
