(function ($) {
    'use strict';
    // Display controls only; curriculum actions continue using the existing handlers.
    $(document).on('click', '[data-di-structure-expand]', function () {
        const action = this.getAttribute('data-di-structure-expand');
        if (action === 'show' || action === 'hide') {
            $('#yearSemAccordion .di-semester-panel .accordion-collapse').collapse(action);
        }
    });
})(jQuery);
