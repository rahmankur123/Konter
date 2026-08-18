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

    const ctx = document.getElementById('salesChart');
    if (ctx && window.Chart) {
        const isKasirModern = document.body.classList.contains('kasir-modern');
        const isSmallScreen = window.matchMedia('(max-width: 576px)').matches;
        const chartWrap = ctx.closest('.chart-canvas-wrap');
        const chartPalette = isKasirModern ? {
            line: '#22d3ee',
            lineSoft: 'rgba(34, 211, 238, 0.18)',
            fillTop: 'rgba(34, 211, 238, 0.38)',
            fillBottom: 'rgba(236, 72, 153, 0.03)',
            point: '#f59e0b',
            pointHover: '#fb7185',
            text: 'rgba(255, 255, 255, 0.82)',
            textStrong: '#ffffff',
            grid: 'rgba(255, 255, 255, 0.1)',
            tooltipBg: 'rgba(18, 24, 57, 0.94)',
            tooltipBorder: 'rgba(125, 211, 252, 0.34)'
        } : {
            line: '#0d6efd',
            lineSoft: 'rgba(13, 110, 253, 0.14)',
            fillTop: 'rgba(13, 110, 253, 0.24)',
            fillBottom: 'rgba(13, 110, 253, 0.02)',
            point: '#0d6efd',
            pointHover: '#198754',
            text: '#6c757d',
            textStrong: '#333333',
            grid: 'rgba(0, 0, 0, 0.06)',
            tooltipBg: 'rgba(20, 24, 39, 0.92)',
            tooltipBorder: 'rgba(13, 110, 253, 0.24)'
        };

        const formatCurrency = value => 'Rp ' + parseInt(value || 0).toLocaleString('id-ID');
        const formatAxisCurrency = value => {
            const amount = parseInt(value || 0);

            if (amount >= 1000000) {
                return 'Rp ' + (amount / 1000000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt';
            }

            if (amount >= 1000) {
                return 'Rp ' + (amount / 1000).toLocaleString('id-ID', { maximumFractionDigits: 0 }) + ' rb';
            }

            return 'Rp ' + amount.toLocaleString('id-ID');
        };

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: window.chartLabels || [],
                datasets: [{
                    label: 'Total Penjualan',
                    data: window.chartData || [],
                    backgroundColor: function(context) {
                        const chart = context.chart;
                        const area = chart.chartArea;

                        if (!area) {
                            return chartPalette.lineSoft;
                        }

                        const gradient = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
                        gradient.addColorStop(0, chartPalette.fillTop);
                        gradient.addColorStop(1, chartPalette.fillBottom);
                        return gradient;
                    },
                    borderColor: chartPalette.line,
                    borderWidth: 4,
                    fill: true,
                    tension: 0.42,
                    pointBackgroundColor: chartPalette.point,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 3,
                    pointRadius: 5,
                    pointHoverBackgroundColor: chartPalette.pointHover,
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 3,
                    pointHoverRadius: 8,
                    pointHitRadius: 16
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: !chartWrap,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            boxWidth: 34,
                            boxHeight: 4,
                            color: chartPalette.text,
                            padding: 18,
                            font: {
                                size: 12,
                                weight: '600'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: chartPalette.tooltipBg,
                        borderColor: chartPalette.tooltipBorder,
                        borderWidth: 1,
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        displayColors: false,
                        padding: 14,
                        cornerRadius: 10,
                        callbacks: {
                            title: function(context) {
                                return 'Tanggal ' + context[0].label;
                            },
                            label: function(context) {
                                return 'Penjualan: ' + formatCurrency(context.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        border: {
                            display: false
                        },
                        ticks: {
                            maxTicksLimit: isSmallScreen ? 6 : 8,
                            callback: function(value) {
                                return formatAxisCurrency(value);
                            },
                            color: chartPalette.text,
                            padding: 12,
                            font: {
                                size: 11,
                                weight: '600'
                            }
                        },
                        grid: {
                            color: chartPalette.grid,
                            drawTicks: false
                        }
                    },
                    x: {
                        border: {
                            display: false
                        },
                        ticks: {
                            autoSkip: true,
                            maxRotation: isSmallScreen ? 0 : 50,
                            minRotation: 0,
                            color: chartPalette.text,
                            padding: 10,
                            font: {
                                size: 11,
                                weight: '600'
                            }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
});
