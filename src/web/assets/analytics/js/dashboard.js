(function() {
    'use strict';

    let chart = null;
    let currentView = 'total';
    let currentData = null;

    // Color palette for stacked chart segments
    var COLORS = [
        { bg: 'rgba(225, 65, 60, 0.7)',  border: 'rgba(225, 65, 60, 1)' },
        { bg: 'rgba(52, 131, 210, 0.7)',  border: 'rgba(52, 131, 210, 1)' },
        { bg: 'rgba(81, 182, 105, 0.7)',  border: 'rgba(81, 182, 105, 1)' },
        { bg: 'rgba(245, 166, 35, 0.7)',  border: 'rgba(245, 166, 35, 1)' },
        { bg: 'rgba(144, 98, 196, 0.7)',  border: 'rgba(144, 98, 196, 1)' },
        { bg: 'rgba(42, 187, 187, 0.7)',  border: 'rgba(42, 187, 187, 1)' },
        { bg: 'rgba(232, 108, 64, 0.7)',  border: 'rgba(232, 108, 64, 1)' },
        { bg: 'rgba(165, 125, 86, 0.7)',  border: 'rgba(165, 125, 86, 1)' },
    ];

    function initDashboard() {
        const chartCanvas = document.getElementById('requestsChart');
        if (!chartCanvas) return;

        currentData = JSON.parse(document.getElementById('chartData').textContent);
        renderChart();

        var toggleContainer = document.getElementById('chartViewToggle');
        if (toggleContainer) {
            toggleContainer.addEventListener('click', function(e) {
                var btn = e.target.closest('.chart-toggle-btn');
                if (!btn) return;
                var view = btn.getAttribute('data-view');
                if (view === currentView) return;

                currentView = view;
                toggleContainer.querySelectorAll('.chart-toggle-btn').forEach(function(b) {
                    b.classList.remove('active');
                });
                btn.classList.add('active');
                renderChart();
            });
        }

        const rangeSelect = document.getElementById('dateRange');
        const siteSelect = document.getElementById('siteSelect');

        if (rangeSelect) {
            rangeSelect.addEventListener('change', fetchData);
        }
        if (siteSelect) {
            siteSelect.addEventListener('change', fetchData);
        }
    }

    function fetchData() {
        const range = document.getElementById('dateRange').value;
        const siteSelect = document.getElementById('siteSelect');
        const siteId = siteSelect ? siteSelect.value : '';

        const params = new URLSearchParams({ range: range });
        if (siteId) {
            params.set('siteId', siteId);
        }

        fetch(Craft.getActionUrl('llm-ready/analytics/data') + '?' + params.toString(), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            currentData = data;
            updateDashboard(data);
        })
        .catch(function(err) {
            console.error('Failed to fetch analytics data:', err);
        });
    }

    function updateDashboard(data) {
        var totalEl = document.getElementById('totalRequests');
        if (totalEl) {
            totalEl.textContent = data.totalRequests.toLocaleString();
        }

        renderChart();
        updateBotTable(data.botBreakdown);
        updateTypeTable(data.requestTypeBreakdown);
        updatePagesTable(data.mostAccessedPages);
    }

    function renderChart() {
        if (!currentData) return;
        var ctx = document.getElementById('requestsChart');
        if (!ctx) return;

        var chartConfig = buildChartConfig();

        if (chart) {
            chart.data = chartConfig.data;
            chart.options = chartConfig.options;
            chart.update();
        } else {
            chart = new Chart(ctx, chartConfig);
        }
    }

    function buildChartConfig() {
        if (currentView === 'total') {
            return buildTotalConfig();
        }

        var breakdownData = currentView === 'bot'
            ? currentData.requestsOverTimeByBot
            : currentData.requestsOverTimeByType;

        return buildStackedConfig(breakdownData);
    }

    function buildTotalConfig() {
        var labels = currentData.requestsOverTime.map(function(r) { return r.date; });
        var values = currentData.requestsOverTime.map(function(r) { return parseInt(r.count); });

        return {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Requests',
                    data: values,
                    backgroundColor: COLORS[0].bg,
                    borderColor: COLORS[0].border,
                    borderWidth: 1,
                    borderRadius: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        stacked: false,
                        ticks: { precision: 0 },
                    },
                    x: {
                        stacked: false,
                        grid: { display: false },
                    },
                },
            },
        };
    }

    function buildStackedConfig(breakdownData) {
        // Collect all unique dates across all groups
        var dateSet = {};
        Object.keys(breakdownData).forEach(function(group) {
            breakdownData[group].forEach(function(row) {
                dateSet[row.date] = true;
            });
        });
        var labels = Object.keys(dateSet).sort();

        // Sort groups by total count descending so the largest is at the bottom
        var groups = Object.keys(breakdownData).sort(function(a, b) {
            var totalA = breakdownData[a].reduce(function(sum, r) { return sum + r.count; }, 0);
            var totalB = breakdownData[b].reduce(function(sum, r) { return sum + r.count; }, 0);
            return totalB - totalA;
        });

        var datasets = groups.map(function(group, i) {
            // Build a date→count lookup for this group
            var countByDate = {};
            breakdownData[group].forEach(function(row) {
                countByDate[row.date] = row.count;
            });

            var color = COLORS[i % COLORS.length];
            return {
                label: group,
                data: labels.map(function(date) { return countByDate[date] || 0; }),
                backgroundColor: color.bg,
                borderColor: color.border,
                borderWidth: 1,
                borderRadius: 3,
            };
        });

        return {
            type: 'bar',
            data: {
                labels: labels,
                datasets: datasets,
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'rectRounded',
                            padding: 16,
                            font: { size: 11 },
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        stacked: true,
                        ticks: { precision: 0 },
                    },
                    x: {
                        stacked: true,
                        grid: { display: false },
                    },
                },
            },
        };
    }

    function clearElement(el) {
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
    }

    function createRow(cells) {
        var tr = document.createElement('tr');
        cells.forEach(function(cell) {
            var td = document.createElement('td');
            if (cell.element) {
                td.appendChild(cell.element);
            } else {
                td.textContent = cell.text || '';
            }
            if (cell.colspan) {
                td.setAttribute('colspan', cell.colspan);
                td.className = 'zilch';
            }
            tr.appendChild(td);
        });
        return tr;
    }

    function emptyRow(colspan, message) {
        return createRow([{ text: message || 'No data yet.', colspan: String(colspan) }]);
    }

    function updateBotTable(botBreakdown) {
        var tbody = document.getElementById('botTableBody');
        if (!tbody) return;
        clearElement(tbody);

        if (botBreakdown.length === 0) {
            tbody.appendChild(emptyRow(3));
            return;
        }

        botBreakdown.forEach(function(row) {
            var strong = document.createElement('strong');
            strong.textContent = row.botName;
            tbody.appendChild(createRow([
                { element: strong },
                { text: parseInt(row.count).toLocaleString() },
                { text: formatDate(row.lastSeen) },
            ]));
        });
    }

    function updateTypeTable(typeBreakdown) {
        var tbody = document.getElementById('typeTableBody');
        if (!tbody) return;
        clearElement(tbody);

        var total = typeBreakdown.reduce(function(sum, r) { return sum + parseInt(r.count); }, 0);

        if (typeBreakdown.length === 0) {
            tbody.appendChild(emptyRow(3));
            return;
        }

        typeBreakdown.forEach(function(row) {
            var pct = total > 0 ? Math.round((parseInt(row.count) / total) * 100) : 0;
            tbody.appendChild(createRow([
                { text: row.requestType },
                { text: parseInt(row.count).toLocaleString() },
                { text: pct + '%' },
            ]));
        });
    }

    function updatePagesTable(pages) {
        var tbody = document.getElementById('pagesTableBody');
        if (!tbody) return;
        clearElement(tbody);

        if (pages.length === 0) {
            tbody.appendChild(emptyRow(3));
            return;
        }

        var baseUrl = JSON.parse(document.getElementById('siteBaseUrl').textContent);

        pages.forEach(function(row) {
            var container = document.createElement('span');
            var mdLink = document.createElement('a');
            if (row.requestPath === '__home__') {
                mdLink.href = baseUrl;
                mdLink.textContent = 'Homepage';
            } else if (row.requestPath === 'llms.txt') {
                mdLink.href = baseUrl + row.requestPath;
                mdLink.textContent = row.requestPath;
            } else {
                mdLink.href = baseUrl + row.requestPath + '.md';
                mdLink.textContent = row.requestPath;
            }
            mdLink.target = '_blank';
            container.appendChild(mdLink);

            if (row.cpEditUrl) {
                var editLink = document.createElement('a');
                editLink.href = row.cpEditUrl;
                editLink.target = '_blank';
                editLink.title = 'Edit entry';
                editLink.className = 'light';
                editLink.style.marginLeft = '4px';
                editLink.textContent = '\u270E';
                container.appendChild(editLink);
            }

            tbody.appendChild(createRow([
                { element: container },
                { text: row.requestType },
                { text: parseInt(row.count).toLocaleString() },
            ]));
        });
    }

    function formatDate(dateStr) {
        if (!dateStr) return '\u2014';
        var d = new Date(dateStr);
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDashboard);
    } else {
        initDashboard();
    }
})();
