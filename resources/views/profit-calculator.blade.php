<!DOCTYPE html>
<html>


<head>
    <title>Just for Information</title>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid black;
            padding: 8px;
            text-align: center;
        }

        th {
            background-color: #f2f2f2;
        }
    </style>
</head>
@php
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
$grand_total = 0;
$profit_total = 0;
$loss_total = 0;
$trades_total = 0;
$profit_order_count = 0;
$loss_order_count = 0;
$symbols = [];
$symbols = DB::table('orders')->pluck('symbol')->unique();

@endphp

<body>


    <center>
        <h1>Dummy Data</h1>
    </center>

    <center>
        <form method="GET" action="{{ route('traderStats', env('BOT_API_KEY')) }}">
            <label for="start_date">Start Date:</label>
            <input type="datetime-local" name="start_date" id="start_date" value="{{ request('start_date') }}">

            <label for="end_date">End Date:</label>
            <input type="datetime-local" name="end_date" id="end_date" value="{{ request('end_date') }}">

            <label for="symbol">Symbol:</label>
            <select type="text" name="symbol" id="symbol" value="{{ request('symbol') }}">
                <option value="">Select a symbol</option>
                @foreach ($symbols as $symbol)
                <option value="{{ $symbol }}" {{ request('symbol') === $symbol ? 'selected' : '' }}>
                    {{ $symbol }}
                </option>
                @endforeach
            </select>
            <label for="trade_acc">Trade Account:</label>
            <select type="text" name="trade_acc" id="trade_acc" value="{{ request('trade_acc') }}">
                <option value="">Select a trade account</option>
                <option value="1" {{ request('trade_acc') == '1' ? 'selected' : '' }}>Account 1</option>
                <option value="2" {{ request('trade_acc') == '2' ? 'selected' : '' }}>Account 2</option>
                <option value="3" {{ request('trade_acc') == '3' ? 'selected' : '' }}>Account 3</option>
            </select>




            <button type="submit">Filter</button>
        </form>

    </center>
    <br>
    <table width="70%">

        <tbody>

            @foreach ($orders as $order)
            @php

            $order_sell = DB::table('orders')
            ->where('orderId', $order->pair_id)
            ->first();

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
                <td>{{ $order->symbol }}</td>
                <td>{{ $order->qty }}</td>
                <td>{{ $order->side }}</td>
                <td>{{ $order->price * $order->qty }}</td>
                <td>{{ $order->price }}</td>
                <td>{{ number_format($order->commission, 10) }}</td>
                <td>{{ $order->commission * $order->commissionUSDT }}</td>
                <td>{{ $order->created_at }}</td>
            </tr>
            @if (empty($order_sell))
            <tr style="background-color: #6c757d ;color: white;">
                <td colspan="8">Order not placed </td>
            </tr>
            <tr>
                <td colspan="8" title="break">&nbsp;</td>
            </tr>

            @php

            continue;
            @endphp
            @endif

            @if ($order_sell->status == 'FILLED' || $order_sell->status == 'CANCELED')
            <tr>
                <td>{{ $order_sell->symbol }}</td>
                <td>{{ $order_sell->qty }}</td>
                <td>{{ $order_sell->side }}</td>
                <td>{{ $order_sell->price * $order_sell->qty }}</td>
                <td>{{ $order_sell->price }}</td>
                <td>{{ number_format($order_sell->commission, 10) }}</td>
                <td>{{ $order_sell->commission * $order_sell->commissionUSDT }}</td>
                <td>{{ $order_sell->created_at }}</td>
            </tr>
            @php
            $profit =
            $order_sell->price * $order_sell->qty -
            $order->price * $order->qty -
            $order->commission * $order->commissionUSDT -
            $order_sell->commission * $order_sell->commissionUSDT;
            $grand_total += $profit;
            if ($profit >= 0) {
            $profit_total += $profit;
            $profit_order_count++;
            } else {
            $loss_total += abs($profit);
            $loss_order_count++;
            }

            $profit_percentage = (($order_sell->price - $order->price)/$order->price) * 100;
            $profit_value = $order_sell->price * $order_sell->qty - $order->price * $order->qty;
            $trades_total++;
            @endphp
            <tr
                style="{{ $profit < 0 ? 'background-color: #dc3545 ;color: white;' : 'background-color: #28a745 ;color:' }}">
                <td colspan="3" align="right" style="text-align:right">Percentage Profit:
                    {{ round($profit_percentage,2) }}%
                </td>
                <td colspan="1" align="center" style="text-align:center">
                    $ {{ round($profit_value,4) }}
                </td>
                <td colspan="1" align="center" style="text-align:center">Profit: $
                    {{ $profit }} <br> ({{ round(($profit/($order->price * $order->qty)) * 100, 2) }} %)
                </td>
                <td>{{ $order_sell->commission + $order->commission }}</td>
                
                <td>$
                    {{ $order_sell->commission * $order_sell->commissionUSDT + $order->commission * $order->commissionUSDT }} <br>
                    ({{   round((($order_sell->commission * $order_sell->commissionUSDT + $order->commission * $order->commissionUSDT)/($order->price * $order->qty)) * 100, 2) }} %)
                </td>
                @php

                // Convert the created_at strings to Carbon instances
                $firstTimestamp = Carbon::parse($order->created_at);
                $secondTimestamp = Carbon::parse($order_sell->created_at);

                // Calculate the duration
                $durationInSeconds = $firstTimestamp->diffInSeconds($secondTimestamp);
                $durationInMinutes = round($firstTimestamp->diffInMinutes($secondTimestamp), 1);

                @endphp

                <td title="Trade Duration">{{ round($durationInMinutes,2) }} min</td>
            </tr>
            @else
            <tr>

                <td colspan="8">Waiting for sell (
                    <form id="buyEventForm" action="{{ route('updateOrder') }}" method="POST"
                        style="display: inline;">
                        <input type="hidden" name="symbol" value="{{ $order->symbol }}">
                        <input type="hidden" name="trade_acc" value="{{ $order->trade_acc }}">
                        <input type="hidden" name="check_update" value="true">
                        <button type="submit">Check Update</button>
                    </form>
                    )
                </td>
            </tr>
            @endif
            <tr>
                <td colspan="8" title="break">&nbsp;</td>
            </tr>
            @endforeach

            <tr
                style="{{ $grand_total < 0 ? 'background-color: #dc3545 ;color: white;' : 'background-color: #28a745 ;color:' }}">
                <td colspan="8">Net Total: $ {{ $grand_total }} </td>
            </tr>
            <tr>
                <td colspan="8" title="break">&nbsp;</td>
            </tr>
            <tr style="background-color: #28a745 ;color:">
                <td colspan="8">Profit Total ({{ $profit_order_count }}): $ {{ $profit_total }} </td>
            </tr>
            <tr>
                <td colspan="8" title="break">&nbsp;</td>
            </tr>
            <tr style="background-color: #dc3545 ;color: white;">
                <td colspan="8">Loss Total ({{ $loss_order_count }}): $ {{ $loss_total }} </td>
            </tr>
            <tr>
                <td colspan="8" title="break">&nbsp;</td>
            </tr>
            <tr style="background-color: #6c757d ;">
                <td colspan="8">Trades Total: {{ $trades_total }} </td>
            </tr>
            <tr>
                <td colspan="8" title="break"> <a style="float:right" href="{{ route('downloadTraderStatsCSV',['start_date'=>request('start_date'),'end_date'=>request('end_date'),'symbol'=>request('symbol'),'trade_acc' =>request('trade_acc')] ) }}" class="btn btn-primary">Download CSV</a>
                </td>
            </tr>
        </tbody>
    </table>

</body>

</html>