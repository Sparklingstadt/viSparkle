**注文番号:** {{ $order->id }}

@foreach ($order->items as $item)
- {{ $item->product_name }} × {{ $item->quantity }}（{{ number_format($item->lineTotal()) }}円）
@endforeach

小計: {{ number_format($order->subtotal) }}円  
送料: {{ number_format($order->shipping_fee) }}円  
**合計: {{ number_format($order->total) }}円**
