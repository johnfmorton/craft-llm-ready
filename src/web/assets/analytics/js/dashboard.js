(function() {
    'use strict';

    let chart = null;

    function initDashboard() {
        const chartCanvas = document.getElementById('requestsChart');
        if (!chartCanvas) return;

        const initialData = JSON.parse(document.getElementById('chartData').textContent);
        renderChart(initialData.requestsOverTime);

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

        renderChart(data.requestsOverTime);
        updateBotTable(data.botBreakdown);
        updateTypeTable(data.requestTypeBreakdown);
        updatePagesTable(data.mostAccessedPages);
    }

    function renderChart(requestsOverTime) {
        const ctx = document.getElementById('requestsChart');
        if (!ctx) return;

        const labels = requestsOverTime.map(function(r) { return r.date; });
        const values = requestsOverTime.map(function(r) { return parseInt(r.count); });

        if (chart) {
            chart.data.labels = labels;
            chart.data.datasets[0].data = values;
            chart.update();
            return;
        }

        chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Requests',
                    data: values,
                    backgroundColor: 'rgba(225, 65, 60, 0.7)',
                    borderColor: 'rgba(225, 65, 60, 1)',
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
                        ticks: { precision: 0 },
                    },
                    x: {
                        grid: { display: false },
                    },
                },
            },
        });
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
