/* OSAEITS Simple - custom JS */
$(function() {
    // Confirm delete
    $('[data-confirm]').on('click', function(e) {
        if (!confirm($(this).data('confirm'))) e.preventDefault();
    });

    // Mobile cardview for all page tables: use table headers as field labels.
    $('.table-responsive table').each(function() {
        var $table = $(this);
        var headers = [];

        $table.find('thead th').each(function() {
            headers.push($.trim($(this).text()));
        });

        if (!headers.length) return;

        $table.find('tbody tr').each(function() {
            $(this).find('td').each(function(index) {
                var label = headers[index] || ('Field ' + (index + 1));
                $(this).attr('data-label', label);
            });
        });
    });
    
    // Sidebar responsive toggle (desktop collapse + mobile overlay open)
    (function() {
        var $body = $('body');
        var storageKey = 'osaeits-sidebar';
        function isMobile() { return window.matchMedia('(max-width: 767.98px)').matches; }
        function applyStored() {
            try {
                var v = localStorage.getItem(storageKey);
                if (!v) return;
                if (v === 'collapsed') {
                    $body.addClass('sidebar-collapsed');
                    $body.removeClass('sidebar-open');
                } else if (v === 'open') {
                    $body.addClass('sidebar-open');
                }
            } catch (e) { /* ignore */ }
        }
        $('#sidebarToggle, #sidebarToggleTop').on('click', function(e) {
            e.preventDefault();
            if (isMobile()) {
                $body.toggleClass('sidebar-open');
            } else {
                $body.toggleClass('sidebar-collapsed');
                try {
                    if ($body.hasClass('sidebar-collapsed')) localStorage.setItem(storageKey, 'collapsed');
                    else localStorage.removeItem(storageKey);
                } catch (e) { /* ignore */ }
            }
        });
        // Close mobile sidebar on outside click/touch
        $(document).on('click touchstart', function(e) {
            if (isMobile() && $body.hasClass('sidebar-open')) {
                if (!$(e.target).closest('#accordionSidebar, #sidebarToggle, #sidebarToggleTop').length) {
                    $body.removeClass('sidebar-open');
                }
            }
        });
        // Escape to close
        $(document).on('keydown', function(e) { if (e.key === 'Escape') $body.removeClass('sidebar-open'); });
        applyStored();
    })();
});
