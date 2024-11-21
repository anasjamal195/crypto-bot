<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">


    <!-- Bootstrap CSS -->
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">

    <!-- Your custom CSS if any -->
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">

    <title>Binance Bot</title>
</head>

<style>
    body {
        font-family: Arial, sans-serif;
    }

    .container {
        max-width: 600px;
        margin: 0 auto;
        padding: 20px;
    }

    form {
        margin-bottom: 20px;
        padding: 20px;
        border: 1px solid #ccc;
        border-radius: 5px;
    }

    .form-group {
        margin-bottom: 10px;
    }

    label {
        display: block;
        margin-bottom: 5px;
    }

    input[type="text"],
    input[type="number"] {
        width: 100%;
        padding: 8px;
        box-sizing: border-box;
    }

    button {
        padding: 10px 15px;
        background-color: #007BFF;
        color: #fff;
        border: none;
        border-radius: 5px;
        cursor: pointer;
    }

    button:hover {
        background-color: #0056b3;
    }
</style>


<body>
    @if (session('success'))
        <div class="alert alert-success text-center">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger text-center">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="container">
        <h1>{{ ucwords(env('APP_NAME')) }}</h1>
        {{-- <div class="form-group">
            <label for="globalApiKey">Binance API Key</label>
            <input type="text" id="globalApiKey" required>
        </div> --}}

        {{-- <form id="formAutotrade1" action="/binance/auto-trade-1" method="GET" onsubmit="copyApiKey('formAutotrade1')">
            <h2>Auto Trade 1</h2>
            <input type="hidden" id="botApiKey" name="botApiKey">
            <div class="form-group">
                <label for="symbol">Symbol</label>
                <input type="text" id="symbol" name="symbol" required>
            </div>
            <div class="form-group">
                <label for="interval">Interval</label>
                <input type="text" id="interval" name="interval" required>
            </div>
            <div class="form-group">
                <label for="stop_limit">Stop Limit</label>
                <input type="number" id="stop_limit" name="stop_limit" step="0.01" required>
            </div>
            <div class="form-group">
                <label for="timeout_limit">Timeout Limit</label>
                <input type="number" id="timeout_limit" name="timeout_limit" step="0.01" required>
            </div>
            <div class="form-group">
                <label for="trade_acc">Trade Account</label>
                <input type="number" id="trade_acc" name="trade_acc" required>
            </div>
            <div class="form-group">
                <label for="buy_coin_price">Buy Coin Price</label>
                <input type="number" id="buy_coin_price" name="buy_coin_price" step="0.01" required>
            </div>
            <button type="submit">Start Auto Trade 1</button>
        </form>

        <form id="formAutotrade2" action="/binance/auto-trade-2" method="GET" onsubmit="copyApiKey('formAutotrade2')">
            <h2>Auto Trade 2</h2>
            <input type="hidden" id="botApiKey" name="botApiKey">
            <div class="form-group">
                <label for="symbol">Symbol</label>
                <input type="text" id="symbol" name="symbol" required>
            </div>
            <div class="form-group">
                <label for="interval">Interval</label>
                <input type="text" id="interval" name="interval" required>
            </div>
            <div class="form-group">
                <label for="stop_limit">Stop Limit</label>
                <input type="number" id="stop_limit" name="stop_limit" step="0.01" required>
            </div>
            <div class="form-group">
                <label for="timeout_limit">Timeout Limit</label>
                <input type="number" id="timeout_limit" name="timeout_limit" step="0.01" required>
            </div>
            <div class="form-group">
                <label for="trade_acc">Trade Account</label>
                <input type="number" id="trade_acc" name="trade_acc" required>
            </div>
            <div class="form-group">
                <label for="buy_coin_price">Buy Coin Price</label>
                <input type="number" id="buy_coin_price" name="buy_coin_price" step="0.01" required>
            </div>
            <button type="submit">Start Auto Trade 2</button>
        </form> --}}
        <form id="formCandleStickWorker1" action="/binance/candlestick-worker-all" method="GET">
            <h2>Candle Stick Worker (Quick Start)</h2>
            <div class="form-group">
                <label for="botApiKey">Bot Api Key</label>
                <input type="text" id="botApiKey" name="botApiKey" required>
            </div>
            <div class="form-group">
                <label for="interval">Interval</label>
                <input type="text" id="interval" name="interval" required>
            </div>
            <div class="form-group">
                <label for="buy_coin_price">Buy Coin Price</label>
                <input type="text" id="buy_coin_price" name="buy_coin_price" required>
            </div>
            <div class="form-group">
                <label for="trade_acc">Trade Account</label>
                <input type="text" id="trade_acc" name="trade_acc" required>
            </div>
            <button type="submit">Quickstart All workers</button>
            {{-- <a href="/binance/candlestick-worker-reset"  class="btn btn-secondary btn-sm" style="float:right" >Reset Workers</a> --}}
        </form>
        
        {{-- <form id="formCandleStickWorker1" action="/binance/candlestick-worker-1" method="GET"
            onsubmit="copyApiKey('formCandleStickWorker1')">
            <h2>Candle Stick Worker 1</h2>
            <input type="hidden" id="botApiKey" name="botApiKey">
            <div class="form-group">
                <label for="symbol">Symbol</label>
                <input type="text" id="symbol" name="symbol" required>
            </div>
            <div class="form-group">
                <label for="interval">Interval</label>
                <input type="text" id="interval" name="interval" required>
            </div>
            <div class="form-group">
                <label for="buy_coin_price">Buy Coin Price</label>
                <input type="text" id="buy_coin_price" name="buy_coin_price" required>
            </div>
            <div class="form-group">
                <label for="trade_acc">Trade Account</label>
                <input type="text" id="trade_acc" name="trade_acc" required>
            </div>
            <button type="submit">Start Candle Stick Worker 1</button>
        </form>

        <form id="formCandleStickWorker2" action="/binance/candlestick-worker-2" method="GET"
            onsubmit="copyApiKey('formCandleStickWorker2')">
            <h2>Candle Stick Worker 2</h2>
            <input type="hidden" id="botApiKey" name="botApiKey">
            <div class="form-group">
                <label for="symbol">Symbol</label>
                <input type="text" id="symbol" name="symbol" required>
            </div>
            <div class="form-group">
                <label for="interval">Interval</label>
                <input type="text" id="interval" name="interval" required>
            </div>
            <div class="form-group">
                <label for="buy_coin_price">Buy Coin Price</label>
                <input type="text" id="buy_coin_price" name="buy_coin_price" required>
            </div>
            <div class="form-group">
                <label for="trade_acc">Trade Account</label>
                <input type="text" id="trade_acc" name="trade_acc" required>
            </div>
            <button type="submit">Start Candle Stick Worker 2</button>
        </form>

        <form id="formCandleStickWorker3" action="/binance/candlestick-worker-3" method="GET"
            onsubmit="copyApiKey('formCandleStickWorker3')">
            <h2>Candle Stick Worker 3</h2>
            <input type="hidden" id="botApiKey" name="botApiKey">
            <div class="form-group">
                <label for="symbol">Symbol</label>
                <input type="text" id="symbol" name="symbol" required>
            </div>
            <div class="form-group">
                <label for="interval">Interval</label>
                <input type="text" id="interval" name="interval" required>
            </div>
            <div class="form-group">
                <label for="buy_coin_price">Buy Coin Price</label>
                <input type="text" id="buy_coin_price" name="buy_coin_price" required>
            </div>
            <div class="form-group">
                <label for="trade_acc">Trade Account</label>
                <input type="text" id="trade_acc" name="trade_acc" required>
            </div>
            <button type="submit">Start Candle Stick Worker 3</button>
        </form> --}}

        {{-- <form id="formRestartQueues" action="/restart-queues" method="GET"
            onsubmit="copyApiKey('formRestartQueues')">
            <h2>Restart Queues</h2>
            <input type="hidden" id="botApiKey" name="botApiKey">
            <button type="submit">Clear Queues and Restart Tasks</button>
        </form> --}}
    </div>

    <script>
        function copyApiKey(formId) {
            const apiKey = document.getElementById('globalApiKey').value;
            document.querySelector(`#${formId} input[name="botApiKey"]`).value = apiKey;
        }
    </script>
</body>

</html>
