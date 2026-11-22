(() => {
    const preview = document.querySelector('[data-faculty-preview]');
    if (!preview) return;

    const linkSelector = 'a[data-preview-link]';
    const panelSelector = '[data-preview-panel]';
    const announcement = preview.querySelector('[data-preview-announcement]');
    const error = preview.querySelector('[data-preview-error]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let region = preview.querySelector('[data-preview-region]');
    let renderedUrl = new URL(window.location.href);
    let pending;

    function isPreviewUrl(url) {
        return url.origin === renderedUrl.origin && url.pathname === renderedUrl.pathname
            && url.searchParams.get('record') === region.dataset.record;
    }

    function showPreview(next, nextPanel, currentPanel) {
        const focus = document.activeElement;
        const menu = region.querySelector('.hris-preview-sections');
        const nextMenu = next.querySelector('.hris-preview-sections');
        nextPanel.classList.add('hris-preview-enter');

        if (menu && nextMenu) {
            const scrollLeft = menu.scrollLeft;
            menu.replaceChildren(...nextMenu.childNodes);
            menu.scrollLeft = scrollLeft;
            currentPanel.replaceWith(nextPanel);
            region.dataset.previewTitle = next.dataset.previewTitle;
        } else {
            region.replaceWith(next);
            region = next;
        }

        if (focus?.matches(linkSelector) && !focus.isConnected) {
            const nextFocus = Array.from(region.querySelectorAll(linkSelector)).find((link) => link.href === focus.href)
                ?? region.querySelector('.hris-preview-tabs [aria-current="page"]');
            nextFocus?.focus({ preventScroll: true });
        } else if (focus?.matches('[data-preview-work]') && !focus.isConnected) {
            nextPanel.querySelector('[data-preview-work]')?.focus({ preventScroll: true });
        }
    }

    function finishNavigation(url, panel, pushHistory) {
        if (pushHistory && window.location.href !== url.href) {
            window.history.pushState(null, '', url);
        }
        renderedUrl = url;

        if (pushHistory && url.hash === '#pds-preview' && panel.id === 'pds-preview') {
            const top = panel.getBoundingClientRect().top;
            const headerBottom = document.querySelector('.hris-navbar')?.getBoundingClientRect().bottom ?? 0;
            if (top < headerBottom || top > window.innerHeight - 80) {
                panel.scrollIntoView({ block: 'start', behavior: reducedMotion.matches ? 'auto' : 'smooth' });
            }
        }
    }

    async function navigate(url, pushHistory, refresh = false) {
        pending?.abort();
        const currentPanel = region.querySelector(panelSelector);
        error.hidden = true;
        if (!refresh && url.search === renderedUrl.search) {
            finishNavigation(url, currentPanel, pushHistory);
            return;
        }

        const request = new AbortController();
        pending = request;
        const requestUrl = new URL(url);
        requestUrl.searchParams.set('partial', 'preview');
        requestUrl.hash = '';
        currentPanel.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(requestUrl, { signal: request.signal });
            if (request.signal.aborted) return;
            if (response.redirected && new URL(response.url).origin === window.location.origin) {
                window.location.assign(response.url);
                return;
            }
            if (!response.ok) throw new Error('Faculty preview failed.');

            const template = document.createElement('template');
            template.innerHTML = await response.text();
            if (request.signal.aborted) return;
            const next = template.content.querySelector('[data-preview-region]');
            const nextPanel = next?.querySelector(panelSelector);
            if (!nextPanel || next.dataset.record !== region.dataset.record) throw new Error('Invalid faculty preview.');

            showPreview(next, nextPanel, currentPanel);
            finishNavigation(url, nextPanel, pushHistory);
            announcement.textContent = refresh ? 'Work history updated.' : next.dataset.previewTitle + ' opened.';
        } catch (failure) {
            if (!request.signal.aborted) error.hidden = false;
        } finally {
            if (pending === request) {
                pending = null;
                currentPanel.removeAttribute('aria-busy');
            }
        }
    }

    preview.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest(linkSelector);
        if (!link || link.target) return;
        const url = new URL(link.href);
        if (!isPreviewUrl(url)) return;

        event.preventDefault();
        navigate(url, true);
    });

    preview.addEventListener('work-experience-saved', event => {
        if (event.target.matches('[data-preview-work]') && !pending) navigate(renderedUrl, false, true);
    });

    window.addEventListener('popstate', () => {
        const url = new URL(window.location.href);
        if (isPreviewUrl(url)) navigate(url, false);
    });
})();
