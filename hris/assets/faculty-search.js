(() => {
    const form = document.querySelector('[data-live-faculty-search]');
    if (!form) return;

    const input = form.elements.namedItem('q');
    const results = document.getElementById('directory-results');
    const announcement = document.querySelector('[data-faculty-search-announcement]');
    const error = document.querySelector('[data-faculty-search-error]');
    const saveAlert = document.querySelector('[data-faculty-save-feedback] .hris-account-alert');
    let typingTimer;
    let pending;
    let saveAlertTimer;

    function cancelSearch() {
        clearTimeout(typingTimer);
        pending?.abort();
    }

    function searchUrl() {
        const pageUrl = new URL(form.action);
        const params = new URLSearchParams(new FormData(form));
        const query = input.value.trim();
        if (query) params.set('q', query);
        else params.delete('q');
        pageUrl.search = params.toString();
        return pageUrl;
    }

    async function refreshResults(pageUrl = searchUrl(), searching = true) {
        cancelSearch();
        const request = new AbortController();
        pending = request;
        const requestUrl = new URL(pageUrl);
        requestUrl.searchParams.set('partial', 'results');

        if (searching) error.hidden = true;
        results.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(requestUrl, { signal: request.signal });
            if (request.signal.aborted) return false;
            if (response.redirected && new URL(response.url).origin === window.location.origin) {
                window.location.assign(response.url);
                return false;
            }
            if (!response.ok) throw new Error('Faculty search failed.');

            const template = document.createElement('template');
            template.innerHTML = await response.text();
            if (request.signal.aborted) return false;
            const next = template.content.querySelector('#directory-results');
            if (!next) throw new Error('Missing faculty results.');

            const position = { left: window.scrollX, top: window.scrollY };
            error.hidden = true;
            results.replaceChildren(...next.childNodes);
            results.dataset.resultCount = next.dataset.resultCount;
            results.dataset.page = next.dataset.page;
            if (searching) {
                const count = Number(next.dataset.resultCount);
                announcement.textContent = count === 0
                    ? 'No matching employee records.'
                    : 'Found ' + count.toLocaleString() + (count === 1 ? ' employee record.' : ' employee records.');
            }
            if (pageUrl.searchParams.has('page')) pageUrl.searchParams.set('page', next.dataset.page);
            window.history.replaceState(window.history.state, '', pageUrl);
            return position;
        } catch (failure) {
            if (searching && !request.signal.aborted) error.hidden = false;
            return false;
        } finally {
            if (pending === request) {
                pending = null;
                results.removeAttribute('aria-busy');
            }
        }
    }

    window.synkHrisRefreshEmployees = async (dialog, opener, message) => {
        const currentUrl = new URL(window.location.href);
        const pageUrl = (currentUrl.searchParams.get('q') || '') === input.value.trim() ? currentUrl : searchUrl();
        const refreshed = await refreshResults(pageUrl, false);
        const position = refreshed || { left: window.scrollX, top: window.scrollY };

        dialog.close();
        const selector = opener.matches('[data-edit-faculty-status]') ? '[data-edit-faculty-status]' : '[data-edit-personnel]';
        const button = Array.from(results.querySelectorAll(selector))
            .find((candidate) => candidate.dataset.recordKey === opener.dataset.recordKey);
        (button || results).focus({ preventScroll: true });
        window.scrollTo(position.left, position.top);

        clearTimeout(saveAlertTimer);
        saveAlert.hidden = false;
        saveAlert.querySelector('span').textContent = refreshed ? message : message + ' The employee list could not refresh.';
        saveAlertTimer = setTimeout(() => { saveAlert.hidden = true; }, 6000);
    };

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
