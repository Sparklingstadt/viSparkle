<x-mail::message>
# ご注文の商品を発送しました

@include('mail.shop._summary')

@if ($order->tracking_number)
**お問い合わせ番号:** {{ $order->tracking_number }}
@endif

<x-mail::button :url="route('orders.show', $order)">
注文の詳細を見る
</x-mail::button>
</x-mail::message>
