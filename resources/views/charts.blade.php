<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crypto Pulse Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            background-color: #121212;
            /* Dark background for the whole page */
            color: #e1e1e1;
            /* Light text color for readability */
        }

        header,
        footer {
            background-color: #262626;
            /* Slightly lighter dark shade for headers and footers */
        }

        header {
            color: #F0B90B;
        }

        .chart-container {
            background-color: #333333;
            /* Dark background for the chart container */
            border: 1px solid #444;
            /* Subtle border for the container */
            border-radius: 8px;
            padding: 20px;
        }

        .form-select,
        .btn-outline-secondary {
            background-color: #333;
            border-color: #666;
            color: #ddd;
        }

        .btn-outline-secondary:hover {
            background-color: #444;
            color: #fff;
        }

        .btn-check:checked+.btn-outline-secondary,
        .btn-check:focus+.btn-outline-secondary {
            background-color: #555;
            color: #fff;
        }
    </style>
</head>

<body>
    <header class="py-3">
        <div class="container">
            <h1 class=" text-center">AI TradeView</h1>
        </div>
    </header>

    <div class="container my-5">
        <div class="row">
            @csrf()
            <div class="col-md-4">
                @php
                    $coins = DB::table('candlesticks')->select(DB::raw('DISTINCT `symbol`'))->get();
                @endphp
                <select class="form-select" id="selectSymbol" aria-label="Select Symbol" style="max-width: 300px;">
                    <option>Select Cryptocurrency</option>
                    @foreach ($coins as $coin)
                        <option selected value="{{ $coin->symbol }}">{{ $coin->symbol }}</option>
                    @endforeach
                    <!-- <option value="ETHUSDT">ETH/USDT</option> -->
                </select>
            </div>
            <div class="col-md-8">
                <div class="btn-group" role="group" aria-label="Interval Selection">

                    @php
                        $intervals = DB::table('candlesticks')->select(DB::raw('DISTINCT `interval`'))->get();

                    @endphp

                    @foreach ($intervals as $interval)
                        <input type="radio" class="btn-check selectInterval" name="interval"
                            id="{{ $interval->interval }}" value="{{ $interval->interval }}" checked>
                        <label class="btn btn-outline-secondary "
                            for="{{ $interval->interval }}">{{ $interval->interval }}</label>
                    @endforeach

                    {{-- <input type="radio" class="btn-check selectInterval" name="interval" id="5m"
                        value="5m">
                    <label class="btn btn-outline-secondary " for="5m">5m</label>

                    <input type="radio" class="btn-check selectInterval" name="interval" id="15m"
                        value="15m">
                    <label class="btn btn-outline-secondary " for="15m">15m</label>

                    <input type="radio" class="btn-check selectInterval" name="interval" id="30m"
                        value="30m">
                    <label class="btn btn-outline-secondary " for="30m">30m</label>

                    <input type="radio" class="btn-check selectInterval" name="interval" id="1h"
                        value="1h">
                    <label class="btn btn-outline-secondary " for="1h">1h</label>

                    <input type="radio" class="btn-check selectInterval" name="interval" id="4h"
                        value="4h">
                    <label class="btn btn-outline-secondary " for="4h">4h</label>

                    <input type="radio" class="btn-check selectInterval" name="interval" id="1d"
                        value="1d">
                    <label class="btn btn-outline-secondary " for="1d">1d</label> --}}
                </div>
            </div>
            @if (isset($_GET['api_key']) && $_GET['api_key'] == 'fc621b21-00be-4c9e-899d-dccca11462b6')
                <div class="row mt-3">
                    <div class="col-md-12">
                        <a href="https://cryptoapis.store/analytics/train?symbol=BTCUSDT&interval=3m&time_step=100"
                            class="btn btn-primary">Train</a>
                        <a href="https://cryptoapis.store/analytics/startDumper" class="btn btn-primary">Start
                            Dumper</a>
                        <a href="https://cryptoapis.store/analytics/start-server" class="btn btn-primary">Start Python
                            Server</a>
                        <a href="https://cryptoapis.store/analytics/stop-server" class="btn btn-primary">Stop Python
                            Server</a>
                    </div>
                </div>
            @endif
        </div>

        <!-- Chart Container -->
        <div class="chart-container mt-4">
            <canvas id="candlestickChart"></canvas>
        </div>

        <!-- Description below the chart -->
        <div class="mt-3 text-muted">
            <p>This tool allows you to track and predict cryptocurrency prices in real-time. Select a cryptocurrency and
                interval to view the chart with historical data and future predictions.</p>
        </div>
    </div>

    <footer class="py-3">
        <div class="container">
            <p class="text-center text-white">© 2024 AI Trading View. All rights reserved.</p>
        </div>
    </footer>

    <script>
        $(document).ready(function() {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('input[name="_token"]').val()
                }
            });
            // Initialize chart with empty data using Chart.js
            const ctx = document.getElementById('candlestickChart').getContext('2d');
            let candlestickChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Open',
                        data: [],
                        borderColor: '#4c75f2', // Blue line for open
                        backgroundColor: 'rgba(76, 117, 242, 0.2)', // Translucent blue
                        fill: false,
                        tension: 0.1
                    }, {
                        label: 'Open Predicted',
                        data: [],
                        borderColor: '#f7552e', // Red line for predicted
                        backgroundColor: 'rgba(247, 85, 46, 0.2)', // Translucent red
                        fill: false,
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Timestamp'
                            },
                            ticks: {
                                color: '#ccc', // Light grey color for ticks
                                display: false
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: 'Open Value'
                            },
                            ticks: {
                                color: '#ccc' // Light grey color for ticks
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: '#e1e1e1' // Light grey color for legend text
                            }
                        }
                    }
                }
            });

            // Function to update chart on symbol or interval change
            function updateChart(symbol, interval) {
                $.ajax({
                    url: '/fetchChart',
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        symbol: symbol,
                        interval: interval
                    }),
                    success: function(response) {
                        candlestickChart.data.labels = response.labels;
                        candlestickChart.data.datasets[0].data = response.openData;
                        candlestickChart.data.datasets[1].data = response.openPredictedData;
                        candlestickChart.update();
                    },
                    error: function(error) {
                        console.error("Error loading the data: ", error);
                    }
                });
            }

            // Event listeners for the dropdowns
            $('#selectSymbol, .selectInterval').change(function() {
                const selectedSymbol = $('#selectSymbol').val();
                const selectedInterval = $('input[name="interval"]:checked').val();
                updateChart(selectedSymbol, selectedInterval);
            });

            const selectedSymbol = $('#selectSymbol').val();
            const selectedInterval = $('input[name="interval"]:checked').val();
            updateChart(selectedSymbol, selectedInterval);
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.min.js"></script>
</body>

</html>
