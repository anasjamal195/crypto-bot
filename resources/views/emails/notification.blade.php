<!DOCTYPE html>
<html>
    @php
    use Carbon\Carbon;
   
@endphp
<head>
    <title>Crypto Api Notification</title>
</head>

<body>
    <h1>Order Placed</h1>
    <h3> Symbol: {{ $details['symbol'] }}</h3>
    <h3> orderId: {{ $details['orderId'] }}</h3>
    <h3> Type: {{ $details['type'] }}</h3>
    <h3> Side: {{ $details['side'] }}</h3>
    <h3> Price : {{ $details['price'] }} USDT</h3>
    <h3> Quantity: {{ $details['qty'] . ' ' . $details['symbol'] }}</h3>
    <hr>
    <h3>{{ isset($details['stop_loss']) ? 'Stop Loss: ' . $details['stop_loss'] . ' USDT' : '' }}</h3>
    <h3>{{ isset($details['dif_lim']) ? 'DIF Limit: ' . $details['dif_lim'] : '' }}</h3>
    <h3>{{ isset($details['dea_lim']) ? 'DEA Limit: ' . $details['dea_lim'] : '' }}</h3>
    <h3>{{ isset($details['per_lim']) ? 'PER Limit: ' . $details['per_lim'] : '' }}</h3>
    <hr>
    {{-- <h3>{{ isset($details['target_sell_price']) ? 'Target Sell Price: ' . $details['target_sell_price'] . ' USDT' : '' }}
    </h3>
    <h3>{{ isset($details['target_profit_percentage']) ? 'Target Profit Percentage: ' . $details['target_profit_percentage'] . ' %' : '' }} --}}
    </h3>
    <h3>{{ isset($details['trade_amount']) ? 'Trade Amount: ' . $details['trade_amount'] . ' USDT' : '' }}</h3>
    <hr>
    <table width="70%">

        <tbody>


            @php

                if ($details['side'] == 'BUY') {
                    $order_buy = DB::table('orders')
                        ->where('orderId', $details['orderId'])
                        ->first();
                    $order_sell = [];
                } else {
                    $order_sell = DB::table('orders')
                        ->where('orderId', $details['orderId'])
                        ->first();
                    $order_buy = DB::table('orders')
                        ->where('pair_id', $order_sell->pair_id)
                        ->first();
                }

            @endphp


            <tr>
                <th width="9%">Coin</th>
                <th width="9%">Coin Qty</th>
                <th width="9%">Type</th>
                <th width="9%">Invested USDT</th>
                <th width="13%">Trade Price</th>
                <th width="16%">Fee (BNB)</th>
                <th width="13%">Fee (USDT)</th>
                <th width="22%">Date/Time</th>
            </tr>
            <tr>
                <td>{{ $order_buy->symbol }}</td>
                <td>{{ $order_buy->qty }}</td>
                <td>{{ $order_buy->side }}</td>
                <td>{{ $order_buy->price * $order_buy->qty }}</td>
                <td>{{ $order_buy->price }}</td>
                <td>{{ number_format($order_buy->commission, 10) }}</td>
                <td>{{ $order_buy->commission * $order_buy->commissionUSDT }}</td>
                <td>{{ $order_buy->created_at }}</td>
            </tr>
            @if (empty($order_sell))
                <tr style = "background-color: #6c757d ;color: white;">
                    <td colspan="8">Order not placed </td>
                </tr>
                <tr>
                    <td colspan="8" title="break">&nbsp;</td>
                </tr>
            @elseif ($order_sell->status == 'FILLED' || $order_sell->status == 'CANCELED')
                <tr>
                    <td>{{ $order_sell->symbol }}</td>
                    <td>{{ $order_sell->qty }}</td>
                    <td>{{ $order_sell->side }}</td>
                    <td>{{ $order_sell->price * $order_sell->qty }}</td>
                    <td>{{ $order_sell->price }}</td>
                    <td>{{ number_format($order_sell->commission, 10) }}</td>
                    <td>{{ $order_sell->commission * $order_sell->commissionUSDT }}</td>
                    <td>{{ $order_sell->updated_at }}</td>
                </tr>
                @php
                    $profit =
                        $order_sell->price * $order_sell->qty -
                        $order_buy->price * $order_buy->qty -
                        $order_buy->commission * $order_buy->commissionUSDT -
                        $order_sell->commission * $order_sell->commissionUSDT;
                @endphp
                <tr
                    style = "{{ $profit < 0 ? 'background-color: #dc3545 ;color: white;' : 'background-color: #28a745 ;color:' }}">
                    <td colspan="5" align="right" style="text-align:right">Profit: $
                        {{ $profit }}
                    </td>
                    <td>{{ $order_sell->commission + $order_buy->commission }}</td>
                    <td>$
                        {{ $order_sell->commission * $order_sell->commissionUSDT + $order_buy->commission * $order_buy->commissionUSDT }}
                    </td>
                    @php

                        // Convert the created_at strings to Carbon instances
                        $firstTimestamp = Carbon::parse($order_buy->created_at);
                        $secondTimestamp = Carbon::parse($order_sell->created_at);

                        // Calculate the duration
                        $durationInSeconds = $firstTimestamp->diffInSeconds($secondTimestamp);
                        $durationInMinutes = round($firstTimestamp->diffInMinutes($secondTimestamp), 1);

                    @endphp

                    <td title="Trade Duration">{{ $durationInMinutes }} min</td>
                </tr>
            @else
                <tr>

                    <td colspan="8">Waiting for sell </td>
                </tr>
            @endif

        </tbody>
    </table>
</body>

</html>
