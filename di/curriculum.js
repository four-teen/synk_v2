(function ($) {
    'use strict';
    const token = document.querySelector('meta[name="di-curriculum-token"]').content;
    $.ajaxPrefilter(function (options) {
        if (!String(options.url).includes('/query_di_')) return;
        if (String(options.type).toUpperCase() !== 'POST') return;
        if (typeof options.data === 'string') {
            if (!/(?:^|&)csrf_token=/.test(options.data)) options.data += (options.data ? '&' : '') + 'csrf_token=' + encodeURIComponent(token);
        } else {
            options.data = Object.assign({}, options.data, {csrf_token: token});
        }
    });
    $(document).ajaxError(function (event, xhr, settings) {
        if (!String(settings.url).includes('/query_di_')) return;
        const raw = String(xhr.responseText || '');
        const message = raw.startsWith('ERROR|') ? raw.slice(6) : 'Unable to complete this request. Reload the page and try again.';
        if (window.Swal) Swal.fire('Action could not be completed', message, 'warning');
    });
    document.addEventListener('click', function (event) {
        const formIds = {btnSaveProgram: 'addProgramForm', btnUpdateProgram: 'editProgramForm', btnSaveSubject: 'addSubjectForm', btnUpdateSubject: 'editSubjectForm'};
        const button = event.target.closest('button');
        if (!button || !formIds[button.id]) return;
        const form = document.getElementById(formIds[button.id]);
        if (form && !form.reportValidity()) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
})(jQuery);
