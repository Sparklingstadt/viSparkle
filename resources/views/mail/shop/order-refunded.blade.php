<x-mail::message>
# ご注文を取り消し、返金しました

@include('mail.shop._summary')

**返金額:** {{ number_format($order->refunded_amount ?: $order->total) }}円

返金がお手元に反映されるまで、カード会社によって数日かかることがあります。

<x-mail::button :url="route('orders.show', $order)">
注文の詳細を見る
</x-mail::button>
</x-mail::message>
