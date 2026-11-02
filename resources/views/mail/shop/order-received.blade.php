<x-mail::message>
# 新しい注文が入りました

@include('mail.shop._summary')

お届け先: {{ $order->shipping_name }}

<x-mail::button :url="route('creator.orders.show', $order)">
注文を確認する
</x-mail::button>
</x-mail::message>
