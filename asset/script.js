document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const overlays = document.querySelectorAll('#overlay, .overlay');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');

    function closeSidebar() {
        if (sidebar) {
            sidebar.classList.remove('active');
        }
        overlays.forEach(overlay => overlay.classList.remove('active'));
    }

    if (sidebar && mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            overlays.forEach(overlay => overlay.classList.toggle('active'));
        });
    }

    overlays.forEach(overlay => {
        overlay.addEventListener('click', closeSidebar);
    });

    document.querySelectorAll('.sidebar-menu a').forEach(link => {
        if (link.href === window.location.href) {
            link.classList.add('active');
        }
        link.addEventListener('click', closeSidebar);
    });

    const logoutBtn = document.querySelector('.btn-logout');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            if (!confirm('Apakah Anda yakin ingin keluar dari sistem?')) {
                e.preventDefault();
            }
        });
    }

    function enhanceMobileTables(root) {
        const scope = root || document;
        scope.querySelectorAll('.table-container table.table').forEach(table => {
            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());

            if (!headers.length) {
                return;
            }

            table.querySelectorAll('tbody tr').forEach(row => {
                const cells = Array.from(row.children).filter(cell => cell.tagName === 'TD');

                if (cells.length === 1 && cells[0].hasAttribute('colspan')) {
                    row.classList.add('mobile-empty-row');
                    return;
                }

                cells.forEach((cell, index) => {
                    cell.dataset.label = headers[index] || '';
                });
            });
        });
    }

    enhanceMobileTables(document);

    const tableObserver = new MutationObserver(mutations => {
        if (mutations.some(mutation => mutation.addedNodes.length)) {
            enhanceMobileTables(document);
        }
    });

    tableObserver.observe(document.body, {
        childList: true,
        subtree: true
    });
});
