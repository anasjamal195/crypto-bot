<!DOCTYPE html>
<html>
<head>
    <title>Binance Chart</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
</head>
<body>
    <canvas id="chart" width="800" height="400"></canvas>
    <script>
        const data = @json($data);
        const decisions = @json($decisions);
        const labels = data.map(d => new Date(d[0]).toLocaleString());
        const prices = data.map(d => ({ t: new Date(d[0]), o: parseFloat(d[1]), h: parseFloat(d[2]), l: parseFloat(d[3]), c: parseFloat(d[4]) }));

        const buySignals = decisions.filter(d => d.decision === 'Buy').map(d => d.timestamp);
        const sellSignals = decisions.filter(d => d.decision === 'Sell').map(d => d.timestamp);

        const buyPoints = prices.filter(p => buySignals.includes(p.t.toLocaleString()));
        const sellPoints = prices.filter(p => sellSignals.includes(p.t.toLocaleString()));

        const ctx = document.getElementById('chart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'candlestick',
            data: {
                datasets: [{
                    label: 'BTCUSDT',
                    data: prices,
                    borderColor: 'rgba(0, 0, 0, 1)',
                    backgroundColor: 'rgba(0, 0, 0, 0.1)'
                }, {
                    label: 'Buy Signals',
                    type: 'scatter',
                    data: buyPoints.map(p => ({ x: p.t, y: p.o })),
                    borderColor: 'rgba(0, 255, 0, 1)',
                    backgroundColor: 'rgba(0, 255, 0, 0.1)',
                    pointRadius: 5,
                    pointBackgroundColor: 'rgba(0, 255, 0, 1)'
                }, {
                    label: 'Sell Signals',
                    type: 'scatter',
                    data: sellPoints.map(p => ({ x: p.t, y: p.o })),
                    borderColor: 'rgba(255, 0, 0, 1)',
                    backgroundColor: 'rgba(255, 0, 0, 0.1)',
                    pointRadius: 5,
                    pointBackgroundColor: 'rgba(255, 0, 0, 1)'
                }]
            },
            options: {
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            unit: 'minute'
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
